<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Domain\EventDeduplicator;
use NutriHelper\Http\GreenApiClient;

Config::load(__DIR__ . '/../.env');

/**
 * Runs every N minutes via cron. Every reminder/summary cron in this project
 * silently does nothing if the underlying WhatsApp session is disconnected
 * (getStateInstance returns anything other than "authorized") — there's no
 * other signal that would surface that. This alerts once per incident
 * instead of spamming on every run while the instance stays down.
 *
 * Configure in cron every 5-15 minutes:
 *   (star)/10 (star) (star) (star) (star) php /ruta/a/nutri-helper/bin/check_instance_health.php
 *   (replace each "(star)" with a literal asterisk — spelled out here so it
 *   doesn't collide with this comment block's own closing token)
 *
 * Optionally set NUTRI_HEALTH_ALERT_WEBHOOK_URL (e.g. a Slack/Discord
 * incoming webhook, or your own alerting endpoint) to receive the alert
 * outside of WhatsApp itself, since WhatsApp delivery is exactly what's
 * broken when this fires.
 */
$greenApi = new GreenApiClient(Config::greenApi());
$result = $greenApi->getStateInstance();

$stateName = is_array($result['data']) ? (string)($result['data']['stateInstance'] ?? '') : '';
$healthy = $result['status'] < 400 && $stateName === 'authorized';

$dedup = new EventDeduplicator(__DIR__ . '/../storage/locks', 3600);

if (!$healthy) {
    error_log(sprintf(
        '[health] %s Nutri Helper: instancia Green API no autorizada (status=%s, state=%s, error=%s)',
        date('c'),
        (string)$result['status'],
        $stateName !== '' ? $stateName : '(desconocido)',
        $result['error'] ?? 'none'
    ));

    // Only alert once per hour while the outage persists, not on every
    // 5-10 minute cron tick.
    if ($dedup->claim('instance-unhealthy-' . date('YmdH'))) {
        $alertUrl = Config::getOptional('NUTRI_HEALTH_ALERT_WEBHOOK_URL', '');
        if ($alertUrl !== '') {
            $ch = curl_init($alertUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode([
                    'text' => "⚠️ Nutri Helper: instancia Green API no autorizada (state={$stateName})",
                ], JSON_UNESCAPED_UNICODE),
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    echo json_encode(['ok' => false, 'state' => $stateName], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}

echo json_encode(['ok' => true, 'state' => $stateName], JSON_UNESCAPED_UNICODE), PHP_EOL;
