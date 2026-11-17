<?php

declare(strict_types=1);

namespace Xyr\Payments;

/**
 * Minimal Stripe REST client using curl.
 *
 * Every write carries an Idempotency-Key derived from the order, so a retry
 * after a timeout returns the session Stripe already created instead of
 * creating a second one. That makes retrying safe, so network errors, 429s
 * and 5xx responses are retried with exponential backoff.
 */
final class StripeHttpGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly string $secretKey,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 250,
    ) {
        if (!str_starts_with($secretKey, 'sk_test_') && !str_starts_with($secretKey, 'sk_live_')) {
            throw new \InvalidArgumentException('Expected a Stripe secret key (sk_test_... or sk_live_...).');
        }
    }

    public function createCheckoutSession(
        int $orderId,
        string $currency,
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
    ): array {
        $params = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $orderId,
            'metadata' => ['order_id' => (string) $orderId],
            'line_items' => [],
        ];
        foreach ($lineItems as $item) {
            $params['line_items'][] = [
                'quantity' => $item['quantity'],
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $item['unit_amount'],
                    'product_data' => ['name' => $item['name']],
                ],
            ];
        }

        $session = $this->request('POST', '/checkout/sessions', $params, $idempotencyKey);
        return ['id' => $session['id'], 'url' => $session['url']];
    }

    public function checkoutSessionsSince(int $createdAfter): iterable
    {
        $startingAfter = null;
        do {
            $query = ['limit' => 100, 'created' => ['gte' => $createdAfter]];
            if ($startingAfter !== null) {
                $query['starting_after'] = $startingAfter;
            }
            $page = $this->request('GET', '/checkout/sessions?' . http_build_query($query));
            foreach ($page['data'] as $session) {
                yield $session;
                $startingAfter = $session['id'];
            }
        } while (!empty($page['has_more']));
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->secretKey];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $lastError = 'unknown error';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $ch = curl_init(self::API . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
            ]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $status >= 200 && $status < 300) {
                return json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            }

            $retryable = $body === false || $status === 429 || $status >= 500;
            $lastError = $body === false ? "network error: $curlError" : "HTTP $status: $body";
            if (!$retryable || $attempt === $this->maxAttempts) {
                break;
            }
            usleep($this->baseDelayMs * 1000 * (2 ** ($attempt - 1)));
        }

        throw new \RuntimeException("Stripe $method $path failed: $lastError");
    }
}
