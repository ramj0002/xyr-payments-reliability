<?php

declare(strict_types=1);

namespace Xyr\Payments;

/**
 * Verifies the Stripe-Signature header on incoming webhooks.
 *
 * Stripe signs "{timestamp}.{raw body}" with HMAC-SHA256 using the endpoint's
 * signing secret, and sends "t=<timestamp>,v1=<signature>[,v1=...]".
 * Rejecting old timestamps stops an attacker replaying a captured event.
 */
final class WebhookSignature
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * @return array<string,mixed> the decoded event
     * @throws SignatureException
     */
    public static function verify(
        string $payload,
        string $header,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new SignatureException('Malformed Stripe-Signature header.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new SignatureException('Signature does not match.');
        }

        $now ??= time();
        if (abs($now - $timestamp) > $toleranceSeconds) {
            throw new SignatureException('Timestamp outside the tolerance window (possible replay).');
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['id'], $event['type'])) {
            throw new SignatureException('Payload is not a Stripe event.');
        }
        return $event;
    }

    /** Builds a valid header, for tests and local tooling. */
    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
