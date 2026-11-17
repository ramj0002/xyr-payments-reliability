<?php

declare(strict_types=1);

namespace Xyr\Payments\Tests;

use Xyr\Payments\PaymentGateway;

/**
 * In-memory stand-in for Stripe. Like the real API, a repeated idempotency
 * key returns the original session instead of creating a new one.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var array<string, array<string,mixed>> sessions by id */
    public array $sessions = [];
    /** @var array<string, string> idempotency key => session id */
    private array $byKey = [];
    public int $createCalls = 0;

    public function createCheckoutSession(
        int $orderId,
        string $currency,
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
    ): array {
        $this->createCalls++;
        if (isset($this->byKey[$idempotencyKey])) {
            $session = $this->sessions[$this->byKey[$idempotencyKey]];
            return ['id' => $session['id'], 'url' => $session['url']];
        }
        $id = 'cs_test_' . (count($this->sessions) + 1);
        $this->sessions[$id] = [
            'id' => $id,
            'url' => "https://checkout.stripe.test/$id",
            'payment_status' => 'unpaid',
            'amount_total' => array_sum(array_map(fn ($i) => $i['unit_amount'] * $i['quantity'], $lineItems)),
            'currency' => $currency,
            'payment_intent' => null,
            'metadata' => ['order_id' => (string) $orderId],
            'line_items' => $lineItems,
        ];
        $this->byKey[$idempotencyKey] = $id;
        return ['id' => $id, 'url' => $this->sessions[$id]['url']];
    }

    public function checkoutSessionsSince(int $createdAfter): iterable
    {
        return array_values($this->sessions);
    }

    /** Simulates the customer paying on Stripe's page. */
    public function markPaid(string $sessionId, ?int $amountTotal = null): void
    {
        $this->sessions[$sessionId]['payment_status'] = 'paid';
        $this->sessions[$sessionId]['payment_intent'] = 'pi_' . $sessionId;
        if ($amountTotal !== null) {
            $this->sessions[$sessionId]['amount_total'] = $amountTotal;
        }
    }
}
