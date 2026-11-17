<?php

declare(strict_types=1);

namespace Xyr\Payments\Tests;

use Xyr\Payments\Quarantine;
use Xyr\Payments\WebhookHandler;

final class WebhookHandlerTest extends DatabaseTestCase
{
    public function testFulfilsAPaidOrder(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 2]]);
        $outcome = (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order));

        $this->assertSame(WebhookHandler::PROCESSED, $outcome);
        $this->assertSame('paid', $this->scalar('SELECT status FROM payment_orders WHERE id = ?', [$order['order_id']]));
        $this->assertSame(3, $this->stock(1, 'One Size'));
        $this->assertSame('385.00', $this->scalar('SELECT sale_price FROM sales'));
    }

    public function testTheSameEventDeliveredTwiceIsAppliedOnce(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 2]]);
        $handler = new WebhookHandler($this->db);

        $handler->handle($this->paidEvent('evt_1', $order));
        $second = $handler->handle($this->paidEvent('evt_1', $order));

        $this->assertSame(WebhookHandler::DUPLICATE, $second);
        $this->assertSame(3, $this->stock(1, 'One Size'));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sales'));
    }

    public function testADifferentEventForAnAlreadyPaidOrderIsANoOp(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $handler = new WebhookHandler($this->db);

        $handler->handle($this->paidEvent('evt_1', $order));
        $second = $this->paidEvent('evt_2', $order);
        $second['type'] = 'checkout.session.async_payment_succeeded';
        $outcome = $handler->handle($second);

        $this->assertSame(WebhookHandler::DUPLICATE, $outcome);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sales'));
    }

    public function testQuarantinesAnAmountMismatchWithoutFulfilling(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $outcome = (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order, ['amount_total' => 100]));

        $this->assertSame(WebhookHandler::QUARANTINED, $outcome);
        $this->assertSame(5, $this->stock(1, 'One Size'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sales'));
        $this->assertSame(Quarantine::AMOUNT_MISMATCH, $this->scalar('SELECT reason FROM payment_quarantine'));
        $this->assertSame('quarantined', $this->scalar('SELECT status FROM payment_orders'));
    }

    public function testQuarantinesACurrencyMismatch(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $outcome = (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order, ['currency' => 'usd']));

        $this->assertSame(WebhookHandler::QUARANTINED, $outcome);
        $this->assertSame(Quarantine::CURRENCY_MISMATCH, $this->scalar('SELECT reason FROM payment_quarantine'));
    }

    public function testQuarantinesAPaymentForAnUnknownOrder(): void
    {
        $order = ['order_id' => 999, 'session_id' => 'cs_unknown', 'amount_cents' => 100];
        $outcome = (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order));

        $this->assertSame(WebhookHandler::QUARANTINED, $outcome);
        $this->assertSame(Quarantine::UNKNOWN_ORDER, $this->scalar('SELECT reason FROM payment_quarantine'));
    }

    public function testQuarantinesWhenStockSoldOutBeforePaymentLanded(): void
    {
        $order = $this->startOrder([2 => ['M' => 2]]);
        $this->db->exec("UPDATE product_variants SET stock = 1 WHERE product_id = 2"); // sold elsewhere meanwhile

        $outcome = (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order));

        $this->assertSame(WebhookHandler::QUARANTINED, $outcome);
        $this->assertSame(1, $this->stock(2, 'M')); // never goes negative
        $this->assertSame(Quarantine::INSUFFICIENT_STOCK, $this->scalar('SELECT reason FROM payment_quarantine'));
    }

    public function testIgnoresACompletedSessionThatIsNotPaidYet(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $outcome = (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order, ['payment_status' => 'unpaid']));

        $this->assertSame(WebhookHandler::IGNORED, $outcome);
        $this->assertSame('pending', $this->scalar('SELECT status FROM payment_orders'));
    }

    public function testMarksAnExpiredSessionAsFailed(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $event = $this->paidEvent('evt_1', $order, ['payment_status' => 'unpaid']);
        $event['type'] = 'checkout.session.expired';

        (new WebhookHandler($this->db))->handle($event);
        $this->assertSame('failed', $this->scalar('SELECT status FROM payment_orders'));
    }

    public function testAFailureRollsBackSoStripeCanRetry(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $this->db->exec('DROP TABLE sales'); // simulate the database failing mid-fulfilment

        try {
            (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order));
            $this->fail('Expected the handler to throw.');
        } catch (\PDOException) {
        }

        $this->assertSame(5, $this->stock(1, 'One Size'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payment_events')); // retry will be processed
        $this->assertSame('pending', $this->scalar('SELECT status FROM payment_orders'));
    }
}
