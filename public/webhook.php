<?php

declare(strict_types=1);

// Stripe webhook endpoint.
//   400 -> bad signature: Stripe should not retry a forged or corrupted request.
//   500 -> processing failed and was rolled back: Stripe retries with backoff.
//   200 -> applied, a duplicate, ignored, or quarantined for a person to review.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use Xyr\Payments\SignatureException;
use Xyr\Payments\WebhookHandler;
use Xyr\Payments\WebhookSignature;

$payload = (string) file_get_contents('php://input'); // raw body: re-encoded JSON would break the signature
$header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = WebhookSignature::verify($payload, $header, env('STRIPE_WEBHOOK_SECRET'));
} catch (SignatureException $e) {
    http_response_code(400);
    echo $e->getMessage();
    exit;
}

try {
    $outcome = (new WebhookHandler(db()))->handle($event);
    error_log("stripe event {$event['id']} ({$event['type']}): $outcome");
    http_response_code(200);
    echo json_encode(['received' => true, 'outcome' => $outcome]);
} catch (Throwable $e) {
    error_log("stripe event {$event['id']} failed, will be retried: " . $e->getMessage());
    http_response_code(500);
    echo 'Processing failed';
}
