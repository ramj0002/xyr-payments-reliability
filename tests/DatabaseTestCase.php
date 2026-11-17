<?php

declare(strict_types=1);

namespace Xyr\Payments\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/** Fresh in-memory SQLite database per test, seeded with two fictional products. */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;
    protected FakeGateway $gateway;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec((string) file_get_contents(__DIR__ . '/../sql/test.sqlite.sql'));
        $this->db->exec("INSERT INTO products (id, name, price, category) VALUES
            (1, 'Black Square Glasses', '385.00', 'Accessories'),
            (2, 'Waxed Denim Jacket', '19.99', 'Clothing')");
        $this->db->exec("INSERT INTO product_variants (product_id, size, stock) VALUES
            (1, 'One Size', 5), (2, 'M', 2)");
        $this->gateway = new FakeGateway();
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    protected function stock(int $productId, string $size): int
    {
        return (int) $this->scalar('SELECT stock FROM product_variants WHERE product_id = ? AND size = ?', [$productId, $size]);
    }

    /** A Stripe checkout.session.completed event for an order. */
    protected function paidEvent(string $eventId, array $order, array $overrides = []): array
    {
        return [
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => array_merge([
                'id' => $order['session_id'],
                'payment_status' => 'paid',
                'amount_total' => $order['amount_cents'],
                'currency' => 'sgd',
                'payment_intent' => 'pi_' . $order['order_id'],
                'metadata' => ['order_id' => (string) $order['order_id']],
            ], $overrides)],
        ];
    }

    /** Creates an order through CheckoutService and returns its key facts. */
    protected function startOrder(array $cart): array
    {
        $service = new \Xyr\Payments\CheckoutService($this->db, $this->gateway);
        $result = $service->start(42, $cart, 'https://xyr.test/ok', 'https://xyr.test/cancel');
        $row = $this->db->query('SELECT * FROM payment_orders WHERE id = ' . (int) $result['order_id'])->fetch(PDO::FETCH_ASSOC);
        return ['order_id' => (int) $row['id'], 'session_id' => $row['stripe_session_id'], 'amount_cents' => (int) $row['amount_cents']];
    }
}
