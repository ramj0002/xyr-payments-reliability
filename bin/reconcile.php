<?php

declare(strict_types=1);

// Usage: php bin/reconcile.php [--hours=24]
// Prints a JSON report. Exits with code 2 when anything was quarantined,
// so a cron job or CI step can alert on it.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use Xyr\Payments\Quarantine;
use Xyr\Payments\Reconciler;
use Xyr\Payments\StripeHttpGateway;
use Xyr\Payments\WebhookHandler;

$hours = (int) (getopt('', ['hours::'])['hours'] ?? 24);
$db = db();

$report = (new Reconciler($db, new StripeHttpGateway(env('STRIPE_SECRET_KEY')), new WebhookHandler($db)))
    ->run(time() - $hours * 3600);
$report['open_quarantine'] = count((new Quarantine($db))->open());

echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
exit($report['quarantined'] > 0 ? 2 : 0);
