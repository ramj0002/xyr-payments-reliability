<?php

declare(strict_types=1);

namespace Xyr\Payments;

use PDO;

/**
 * Compares what Stripe says happened with what XYR's database says, and fixes
 * or quarantines the differences. Run it on a schedule (bin/reconcile.php).
 *
 *  - Paid in Stripe, still pending here  -> a webhook was missed: replay it
 *    through WebhookHandler, so the same checks and idempotency apply.
 *  - Paid here, not paid (or missing) in Stripe -> quarantine; never trust
 *    a local "paid" that Stripe can't confirm.
 *  - Amount, currency or stock problems -> quarantined by WebhookHandler.
 *
 * Safe to re-run: replays use a stable event id, quarantine entries are unique.
 */
final class Reconciler
{
    public function __construct(
        private readonly PDO $db,
        private readonly PaymentGateway $gateway,
        private readonly WebhookHandler $handler,
    ) {
    }

    /** @return array{checked:int, ok:int, repaired:int, quarantined:int, ignored:int} */
    public function run(int $since): array
    {
        $report = ['checked' => 0, 'ok' => 0, 'repaired' => 0, 'quarantined' => 0, 'ignored' => 0];
        $seenSessions = [];

        foreach ($this->gateway->checkoutSessionsSince($since) as $session) {
            $report['checked']++;
            $seenSessions[$session['id']] = $session;

            $order = $this->orderFor($session);
            $paidInStripe = ($session['payment_status'] ?? '') === 'paid';

            if ($order !== null && $order['status'] === 'paid' && $paidInStripe) {
                $report['ok']++;
                continue;
            }
            if (!$paidInStripe) {
                if ($order !== null && $order['status'] === 'paid') {
                    (new Quarantine($this->db))->add((int) $order['id'], $session['id'], Quarantine::PAID_LOCALLY_NOT_IN_STRIPE, [
                        'stripe_payment_status' => $session['payment_status'] ?? null,
                    ]);
                    $report['quarantined']++;
                } else {
                    $report['ignored']++;
                }
                continue;
            }

            // Paid in Stripe but not here: replay the event we should have received.
            $outcome = $this->handler->handle([
                'id' => 'reconcile_' . $session['id'],
                'type' => 'checkout.session.completed',
                'data' => ['object' => $session],
            ]);
            match ($outcome) {
                WebhookHandler::PROCESSED => $report['repaired']++,
                WebhookHandler::QUARANTINED => $report['quarantined']++,
                default => $report['ok']++,
            };
        }

        // Orders marked paid locally whose session Stripe didn't return at all.
        $paid = $this->db->prepare('SELECT * FROM payment_orders WHERE status = ? AND created_at >= ?');
        $paid->execute(['paid', gmdate('Y-m-d H:i:s', $since)]);
        foreach ($paid->fetchAll(PDO::FETCH_ASSOC) as $order) {
            if ($order['stripe_session_id'] === null || !isset($seenSessions[$order['stripe_session_id']])) {
                $added = (new Quarantine($this->db))->add((int) $order['id'], 'order_' . $order['id'], Quarantine::PAID_LOCALLY_NOT_IN_STRIPE, [
                    'stripe_session_id' => $order['stripe_session_id'],
                ]);
                if ($added) {
                    $report['quarantined']++;
                }
            }
        }

        return $report;
    }

    private function orderFor(array $session): ?array
    {
        $orderId = (int) ($session['metadata']['order_id'] ?? $session['client_reference_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM payment_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
