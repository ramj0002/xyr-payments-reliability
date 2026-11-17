<?php

declare(strict_types=1);

namespace Xyr\Payments;

use PDO;

/**
 * Records that look wrong are set aside here instead of being fulfilled or
 * silently dropped. Nothing downstream (stock, sales reports) is touched until
 * a person resolves the entry.
 *
 * Adding the same (reference, reason) twice is a no-op, so the reconciler can
 * run repeatedly without piling up duplicates.
 */
final class Quarantine
{
    public const UNKNOWN_ORDER = 'unknown_order';
    public const AMOUNT_MISMATCH = 'amount_mismatch';
    public const CURRENCY_MISMATCH = 'currency_mismatch';
    public const INSUFFICIENT_STOCK = 'insufficient_stock_after_payment';
    public const PAID_LOCALLY_NOT_IN_STRIPE = 'paid_locally_not_in_stripe';
    public const PAID_IN_STRIPE_BUT_ORDER_CLOSED = 'paid_in_stripe_but_order_closed';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $details */
    public function add(?int $orderId, string $reference, string $reason, array $details = []): bool
    {
        $exists = $this->db->prepare('SELECT 1 FROM payment_quarantine WHERE reference = ? AND reason = ?');
        $exists->execute([$reference, $reason]);
        if ($exists->fetchColumn()) {
            return false;
        }

        $insert = $this->db->prepare(
            'INSERT INTO payment_quarantine (order_id, reference, reason, details, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $orderId,
            $reference,
            $reason,
            json_encode($details, JSON_UNESCAPED_SLASHES),
            gmdate('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function open(): array
    {
        return $this->db
            ->query('SELECT * FROM payment_quarantine WHERE resolved_at IS NULL ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}
