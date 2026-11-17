<?php

declare(strict_types=1);

namespace Xyr\Payments\Tests;

use PHPUnit\Framework\TestCase;
use Xyr\Payments\SignatureException;
use Xyr\Payments\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const NOW = 1_800_000_000;
    private string $payload = '{"id":"evt_1","type":"checkout.session.completed","data":{"object":{}}}';

    public function testAcceptsAValidSignature(): void
    {
        $header = WebhookSignature::header($this->payload, self::SECRET, self::NOW);
        $event = WebhookSignature::verify($this->payload, $header, self::SECRET, now: self::NOW + 10);
        $this->assertSame('evt_1', $event['id']);
    }

    public function testAcceptsWhenAnyOfSeveralSignaturesMatches(): void
    {
        // Stripe sends two v1 signatures while a signing secret is being rotated.
        $valid = WebhookSignature::header($this->payload, self::SECRET, self::NOW);
        $header = 't=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',' . explode(',', $valid)[1];
        $event = WebhookSignature::verify($this->payload, $header, self::SECRET, now: self::NOW);
        $this->assertSame('evt_1', $event['id']);
    }

    public function testRejectsATamperedPayload(): void
    {
        $header = WebhookSignature::header($this->payload, self::SECRET, self::NOW);
        $this->expectException(SignatureException::class);
        WebhookSignature::verify(str_replace('evt_1', 'evt_2', $this->payload), $header, self::SECRET, now: self::NOW);
    }

    public function testRejectsTheWrongSecret(): void
    {
        $header = WebhookSignature::header($this->payload, 'whsec_attacker', self::NOW);
        $this->expectException(SignatureException::class);
        WebhookSignature::verify($this->payload, $header, self::SECRET, now: self::NOW);
    }

    public function testRejectsAReplayedOldEvent(): void
    {
        $header = WebhookSignature::header($this->payload, self::SECRET, self::NOW);
        $this->expectException(SignatureException::class);
        WebhookSignature::verify($this->payload, $header, self::SECRET, now: self::NOW + 301);
    }

    public function testRejectsAMalformedHeader(): void
    {
        $this->expectException(SignatureException::class);
        WebhookSignature::verify($this->payload, 'garbage', self::SECRET, now: self::NOW);
    }
}
