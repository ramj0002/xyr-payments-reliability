<?php

declare(strict_types=1);

namespace Xyr\Payments;

/**
 * The slice of Stripe this layer depends on. The HTTP version talks to Stripe;
 * tests use an in-memory fake.
 */
interface PaymentGateway
{
    /**
     * @param list<array{name:string,unit_amount:int,quantity:int}> $lineItems
     * @return array{id:string,url:string}
     */
    public function createCheckoutSession(
        int $orderId,
        string $currency,
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
    ): array;

    /**
     * Checkout Sessions created at or after $createdAfter (Unix time).
     *
     * @return iterable<array<string,mixed>>
     */
    public function checkoutSessionsSince(int $createdAfter): iterable;
}
