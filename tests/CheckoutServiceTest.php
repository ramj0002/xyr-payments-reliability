<?php

declare(strict_types=1);

namespace Xyr\Payments\Tests;

use Xyr\Payments\CheckoutService;

final class CheckoutServiceTest extends DatabaseTestCase
{
    public function testCreatesAPendingOrderPricedFromTheDatabase(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 2], 2 => ['M' => 1]]);

        $this->assertSame(2 * 38500 + 1999, $order['amount_cents']);
        $this->assertSame('pending', $this->scalar('SELECT status FROM payment_orders WHERE id = ?', [$order['order_id']]));
        $this->assertSame($order['amount_cents'], $this->gateway->sessions[$order['session_id']]['amount_total']);
    }

    public function testDoesNotTouchStockUntilPaymentIsConfirmed(): void
    {
        $this->startOrder([1 => ['One Size' => 2]]);
        $this->assertSame(5, $this->stock(1, 'One Size'));
    }

    public function testRejectsMoreThanIsInStock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->startOrder([2 => ['M' => 3]]);
    }

    public function testRejectsAnEmptyCart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CheckoutService($this->db, $this->gateway))->start(42, [], 'https://xyr.test/ok', 'https://xyr.test/cancel');
    }
}
