<?php

declare(strict_types=1);

namespace Xyr\Payments;

use PDO;

/**
 * Turns an XYR cart into a pending order and a Stripe Checkout Session.
 *
 * Prices come from the database, never from the browser. Stock is checked
 * here but only decremented once Stripe confirms payment (WebhookHandler),
 * so abandoned checkouts never eat into inventory.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PDO $db,
        private readonly PaymentGateway $gateway,
        private readonly string $currency = 'sgd',
    ) {
    }

    /**
     * @param array<int|string, array<string,int>> $cart XYR session format: [product_id][size] => quantity
     * @return array{order_id:int, checkout_url:string}
     */
    public function start(int $userId, array $cart, string $successUrl, string $cancelUrl): array
    {
        $items = $this->priceCart($cart);

        $this->db->beginTransaction();
        try {
            $total = array_sum(array_map(fn ($i) => $i['unit_amount'] * $i['quantity'], $items));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->prepare(
                'INSERT INTO payment_orders (user_id, status, amount_cents, currency, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$userId, 'pending', $total, $this->currency, $now, $now]);
            $orderId = (int) $this->db->lastInsertId();

            $insertItem = $this->db->prepare(
                'INSERT INTO payment_order_items (order_id, product_id, size, quantity, unit_amount_cents)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $insertItem->execute([$orderId, $item['product_id'], $item['size'], $item['quantity'], $item['unit_amount']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // One key per order: a retried request can never create a second session.
        $session = $this->gateway->createCheckoutSession(
            $orderId,
            $this->currency,
            array_map(fn ($i) => ['name' => $i['name'], 'unit_amount' => $i['unit_amount'], 'quantity' => $i['quantity']], $items),
            $successUrl,
            $cancelUrl,
            'xyr-order-' . $orderId,
        );

        $this->db->prepare('UPDATE payment_orders SET stripe_session_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$session['id'], gmdate('Y-m-d H:i:s'), $orderId]);

        return ['order_id' => $orderId, 'checkout_url' => $session['url']];
    }

    /** @return list<array{product_id:int,size:string,quantity:int,unit_amount:int,name:string}> */
    private function priceCart(array $cart): array
    {
        $lookup = $this->db->prepare(
            'SELECT p.name, p.price, v.stock
             FROM products p JOIN product_variants v ON v.product_id = p.id
             WHERE p.id = ? AND v.size = ?'
        );

        $items = [];
        foreach ($cart as $productId => $sizes) {
            foreach ($sizes as $size => $quantity) {
                $productId = (int) $productId;
                $quantity = (int) $quantity;
                if ($productId <= 0 || $quantity <= 0) {
                    throw new \InvalidArgumentException('Invalid cart line.');
                }
                $lookup->execute([$productId, (string) $size]);
                $row = $lookup->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new \InvalidArgumentException("Product $productId (size $size) not found.");
                }
                if ((int) $row['stock'] < $quantity) {
                    throw new \InvalidArgumentException("Not enough stock for {$row['name']} (size $size).");
                }
                $items[] = [
                    'product_id' => $productId,
                    'size' => (string) $size,
                    'quantity' => $quantity,
                    'unit_amount' => Money::toCents($row['price']),
                    'name' => $row['name'],
                ];
            }
        }
        if ($items === []) {
            throw new \InvalidArgumentException('Your cart is empty.');
        }
        return $items;
    }
}
