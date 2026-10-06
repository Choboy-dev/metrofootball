<?php

declare(strict_types=1);

/**
 * Daily subscription maintenance: expire overdue Premium plans and send renewal emails.
 * Cron example (Hostinger):
 * 0 6 * * * /usr/bin/php /home/u665258891/domains/metrofootball.co.uk/public_html/scripts/cron-subscriptions.php >> /tmp/mf-subscriptions.log 2>&1
 */

$root = dirname(__DIR__);

require_once $root . '/app/Env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/AppUrl.php';
require_once $root . '/app/EmailTemplate.php';
require_once $root . '/app/ResendMailer.php';
require_once $root . '/app/AuthService.php';
require_once $root . '/app/BillingService.php';

use App\BillingService;
use App\Env;

Env::load($root . '/config/.env');

$stats = BillingService::runDailySubscriptionJobs();

echo gmdate('c') . ' subscriptions: '
    . 'expired=' . $stats['expired']
    . ' reminders_7d=' . $stats['reminders_7d']
    . ' reminders_1d=' . $stats['reminders_1d']
    . ' expired_emails=' . $stats['expired_emails']
    . PHP_EOL;
