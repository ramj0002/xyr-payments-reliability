<?php

declare(strict_types=1);

namespace Xyr\Payments\Tests;

use Xyr\Payments\Quarantine;
use Xyr\Payments\Reconciler;
use Xyr\Payments\WebhookHandler;

final class ReconcilerTest extends DatabaseTestCase
{
    private function reconcile(): array
    {
        return (new Reconciler($this->db, $this->gateway, new WebhookHandler($this->db)))->run(0);
    }

    public function testRepairsAPaymentWhoseWebhookWasMissed(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 2]]);
        $this->gateway->markPaid($order['session_id']); // paid on Stripe, webhook never arrived

        $report = $this->reconcile();

        $this->assertSame(1, $report['repaired']);
        $this->assertSame('paid', $this->scalar('SELECT status FROM payment_orders'));
        $this->assertSame(3, $this->stock(1, 'One Size'));
    }

    public function testIsSafeToRunRepeatedly(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 2]]);
        $this->gateway->markPaid($order['session_id']);

        $this->reconcile();
        $second = $this->reconcile();

        $this->assertSame(0, $second['repaired']);
        $this->assertSame(1, $second['ok']);
        $this->assertSame(3, $this->stock(1, 'One Size'));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sales'));
    }

    public function testAgreesWithAWebhookThatAlreadyArrived(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $this->gateway->markPaid($order['session_id']);
        (new WebhookHandler($this->db))->handle($this->paidEvent('evt_1', $order));

        $report = $this->reconcile();

        $this->assertSame(1, $report['ok']);
        $this->assertSame(0, $report['repaired']);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sales'));
    }

    public function testQuarantinesAStripeAmountThatDoesNotMatchTheOrder(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        $this->gateway->markPaid($order['session_id'], amountTotal: 100);

        $report = $this->reconcile();

        $this->assertSame(1, $report['quarantined']);
        $this->assertSame(5, $this->stock(1, 'One Size'));
        $this->assertSame(Quarantine::AMOUNT_MISMATCH, $this->scalar('SELECT reason FROM payment_quarantine'));
    }

    public function testQuarantinesALocalPaidOrderStripeCannotConfirm(): void
    {
        $order = $this->startOrder([1 => ['One Size' => 1]]);
        // Someone marked it paid by hand; Stripe still says unpaid.
        $this->db->exec("UPDATE payment_orders SET status = 'paid'");

        $report = $this->reconcile();

        $this->assertSame(1, $report['quarantined']);
        $this->assertSame(Quarantine::PAID_LOCALLY_NOT_IN_STRIPE, $this->scalar('SELECT reason FROM payment_quarantine'));
    }

    public function testIgnoresUnpaidSessions(): void
    {
        $this->startOrder([1 => ['One Size' => 1]]);
        $report = $this->reconcile();

        $this->assertSame(1, $report['ignored']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payment_quarantine'));
    }
}
