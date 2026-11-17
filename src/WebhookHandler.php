<?php

declare(strict_types=1);

namespace Xyr\Payments;

use PDO;

/**
 * Applies verified Stripe events to XYR's database.
 *
 * Reliability rules:
 *  - Idempotent: each event id is recorded in the same transaction as its
 *    effects. Stripe delivers events at least once, so a repeat is a no-op.
 *  - All or nothing: if processing throws, the transaction (including the
 *    event record) rolls back and the endpoint returns 500, so Stripe retries.
 *  - Quarantine, don't guess: a payment that doesn't match its order (amount,
 *    currency, unknown order, no stock left) is set aside, the order is not
 *    fulfilled, and the event still returns 200 so it isn't retried forever.
 */
final class WebhookHandler
{
    public const PROCESSED = 'processed';
    public const DUPLICATE = 'duplicate';
    public const IGNORED = 'ignored';
    public const QUARANTINED = 'quarantined';

    private readonly Quarantine $quarantine;

    public function __construct(private readonly PDO $db)
    {
        $this->quarantine = new Quarantine($db);
    }

    /** @param array<string,mixed> $event a verified Stripe event */
    public function handle(array $event): string
    {
        $this->db->beginTransaction();
        try {
            if (!$this->recordEvent((string) $event['id'], (string) $event['type'])) {
                $this->db->rollBack();
                return self::DUPLICATE;
            }

            $object = $event['data']['object'] ?? [];
            $outcome = match ($event['type']) {
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded' => $this->onPaid($object),
                'checkout.session.expired',
                'checkout.session.async_payment_failed' => $this->onFailed($object),
                default => self::IGNORED,
            };

            $this->db->prepare('UPDATE payment_events SET outcome = ? WHERE event_id = ?')
                ->execute([$outcome, $event['id']]);
            $this->db->commit();
            return $outcome;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function recordEvent(string $eventId, string $type): bool
    {
        $seen = $this->db->prepare('SELECT 1 FROM payment_events WHERE event_id = ?');
        $seen->execute([$eventId]);
        if ($seen->fetchColumn()) {
            return false;
        }
        $this->db->prepare('INSERT INTO payment_events (event_id, type, received_at) VALUES (?, ?, ?)')
            ->execute([$eventId, $type, gmdate('Y-m-d H:i:s')]);
        return true;
    }

    /** @param array<string,mixed> $session */
    private function onPaid(array $session): string
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return self::IGNORED; // e.g. bank transfer still pending; async_payment_succeeded follows
        }

        $sessionId = (string) ($session['id'] ?? '');
        $order = $this->findOrder($session);
        if ($order === null) {
            $this->quarantine->add(null, $sessionId, Quarantine::UNKNOWN_ORDER, ['metadata' => $session['metadata'] ?? []]);
            return self::QUARANTINED;
        }

        if ($order['status'] === 'paid') {
            return self::DUPLICATE; // a different event for the same payment, e.g. completed + async_succeeded
        }
        if ($order['status'] !== 'pending') {
            $this->quarantineOrder($order, $sessionId, Quarantine::PAID_IN_STRIPE_BUT_ORDER_CLOSED, ['local_status' => $order['status']]);
            return self::QUARANTINED;
        }

        $paidAmount = (int) ($session['amount_total'] ?? -1);
        if ($paidAmount !== (int) $order['amount_cents']) {
            $this->quarantineOrder($order, $sessionId, Quarantine::AMOUNT_MISMATCH, [
                'expected_cents' => (int) $order['amount_cents'],
                'paid_cents' => $paidAmount,
            ]);
            return self::QUARANTINED;
        }
        if (strtolower((string) ($session['currency'] ?? '')) !== strtolower($order['currency'])) {
            $this->quarantineOrder($order, $sessionId, Quarantine::CURRENCY_MISMATCH, [
                'expected' => $order['currency'],
                'paid' => $session['currency'] ?? null,
            ]);
            return self::QUARANTINED;
        }

        $items = $this->orderItems((int) $order['id']);
        $short = $this->itemsWithoutStock($items);
        if ($short !== []) {
            $this->quarantineOrder($order, $sessionId, Quarantine::INSUFFICIENT_STOCK, ['items' => $short]);
            return self::QUARANTINED;
        }

        $this->fulfil($order, $items, $session);
        return self::PROCESSED;
    }

    /** @param array<string,mixed> $session */
    private function onFailed(array $session): string
    {
        $order = $this->findOrder($session);
        if ($order === null || $order['status'] !== 'pending') {
            return self::IGNORED;
        }
        $this->setStatus((int) $order['id'], 'failed');
        return self::PROCESSED;
    }

    private function fulfil(array $order, array $items, array $session): void
    {
        // Conditional decrement: never takes stock below zero, even if two
        // payments race. If it fails, the whole event rolls back and Stripe retries.
        $decrement = $this->db->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE product_id = ? AND size = ? AND stock >= ?'
        );
        $sale = $this->db->prepare(
            'INSERT INTO sales (product_id, quantity_sold, sale_price, size, sale_date) VALUES (?, ?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($items as $item) {
            $decrement->execute([$item['quantity'], $item['product_id'], $item['size'], $item['quantity']]);
            if ($decrement->rowCount() !== 1) {
                throw new \RuntimeException("Stock changed during fulfilment of order {$order['id']}; retrying.");
            }
            $sale->execute([
                $item['product_id'],
                $item['quantity'],
                Money::toDecimal((int) $item['unit_amount_cents']),
                $item['size'],
                $now,
            ]);
        }

        $this->db->prepare(
            'UPDATE payment_orders SET status = ?, stripe_payment_intent = ?, paid_at = ?, updated_at = ? WHERE id = ?'
        )->execute(['paid', $session['payment_intent'] ?? null, $now, $now, $order['id']]);
    }

    /** @return array<string,mixed>|null */
    private function findOrder(array $session): ?array
    {
        $orderId = (int) ($session['metadata']['order_id'] ?? $session['client_reference_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM payment_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // The session must be the one we created for this order.
        if ($order !== null && $order['stripe_session_id'] !== null && $order['stripe_session_id'] !== ($session['id'] ?? null)) {
            return null;
        }
        return $order;
    }

    private function orderItems(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function itemsWithoutStock(array $items): array
    {
        $stock = $this->db->prepare('SELECT stock FROM product_variants WHERE product_id = ? AND size = ?');
        $short = [];
        foreach ($items as $item) {
            $stock->execute([$item['product_id'], $item['size']]);
            $available = (int) $stock->fetchColumn();
            if ($available < (int) $item['quantity']) {
                $short[] = ['product_id' => (int) $item['product_id'], 'size' => $item['size'], 'wanted' => (int) $item['quantity'], 'available' => $available];
            }
        }
        return $short;
    }

    private function quarantineOrder(array $order, string $reference, string $reason, array $details): void
    {
        $this->quarantine->add((int) $order['id'], $reference, $reason, $details);
        $this->setStatus((int) $order['id'], 'quarantined');
    }

    private function setStatus(int $orderId, string $status): void
    {
        $this->db->prepare('UPDATE payment_orders SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, gmdate('Y-m-d H:i:s'), $orderId]);
    }
}
