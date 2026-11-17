<?php

declare(strict_types=1);

// Replaces XYR's api/checkout.php: instead of writing sales straight away,
// it creates a pending order and sends the customer to Stripe Checkout.
// Stock and sales are only updated when the payment is confirmed (webhook.php).

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use Xyr\Payments\CheckoutService;
use Xyr\Payments\StripeHttpGateway;

header('Content-Type: application/json');
session_start();

// Needs $_SESSION['user_id'], which XYR's login_process.php doesn't set yet (see README).
if (($_SESSION['loggedin'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'customer' || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'You must be logged in to check out.']);
    exit;
}

try {
    $service = new CheckoutService(db(), new StripeHttpGateway(env('STRIPE_SECRET_KEY')), env('CURRENCY', 'sgd'));
    $site = env('SITE_URL');
    $result = $service->start(
        (int) $_SESSION['user_id'],
        $_SESSION['cart'] ?? [],
        $site . '/order/success?session_id={CHECKOUT_SESSION_ID}',
        $site . '/cart',
    );
    echo json_encode(['success' => true, 'order_id' => $result['order_id'], 'url' => $result['checkout_url']]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('create_checkout failed: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Payment could not be started. Please try again.']);
}
