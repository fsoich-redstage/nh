<?php
declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Domain\EventDeduplicator;
use NutriHelper\Domain\ImageStore;
use NutriHelper\Domain\MessageRouter;
use NutriHelper\Domain\NutritionAnalysisParser;
use NutriHelper\Domain\WebhookPayloadNormalizer;
use NutriHelper\Http\GreenApiClient;
use NutriHelper\Http\OpenAiClient;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;

Config::load(__DIR__ . '/.env');

header('Content-Type: application/json; charset=utf-8');

$expectedToken = Config::webhookToken();
if ($expectedToken === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook no configurado: falta GREEN_API_WEBHOOK_TOKEN.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$providedToken = resolveWebhookToken();
if (!isValidWebhookToken($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?? '', true);

logWebhookBody($rawBody ?: '(empty body)');

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido o incompleto.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$typeWebhook = (string)($payload['typeWebhook'] ?? $payload['body']['typeWebhook'] ?? '');
if ($typeWebhook !== '' && $typeWebhook !== 'incomingMessageReceived') {
    logWebhookEvent('ignored_webhook_type', ['typeWebhook' => $typeWebhook]);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Webhook no relevante, ignorado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = (new WebhookPayloadNormalizer())->normalize($payload);
logWebhookEvent('normalized', [
    'typeWebhook' => $typeWebhook,
    'typeMessage' => (string)(($payload['body']['messageData']['typeMessage'] ?? $payload['messageData']['typeMessage'] ?? $payload['data']['message']['typeMessage'] ?? $payload['data']['message']['type'] ?? '')),
    'normalizedType' => $message?->type,
    'chatId' => $message?->chatId,
    'body' => $message?->body,
    'idMessage' => $message?->idMessage,
]);

if ($message === null) {
    if (isIgnorableWebhookPayload($payload)) {
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Webhook no relevante, ignorado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido o incompleto.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (str_ends_with($message->chatId, '@g.us')) {
    logWebhookEvent('ignored_group', ['chatId' => $message->chatId, 'idMessage' => $message->idMessage]);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Chat grupal, ignorado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deduplicator = new EventDeduplicator(__DIR__ . '/storage/locks');
if (!$deduplicator->claim($message->idMessage)) {
    logWebhookEvent('duplicate', ['chatId' => $message->chatId, 'body' => $message->body, 'idMessage' => $message->idMessage]);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Evento duplicado, ignorado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $greenApi = new GreenApiClient(Config::greenApi());
    $openAi = new OpenAiClient(Config::get('OPENAI_API_KEY'));
    $conn = Database::connect(Config::database());

    try {
        $greenApi->readChat($message->chatId, $message->idMessage);
    } catch (\Throwable) {
    }

    $router = new MessageRouter(
        $greenApi,
        $openAi,
        new PersonaRepository($conn),
        new NutritionRepository($conn),
        new NutritionAnalysisParser(),
        new ImageStore(Config::getOptional('NUTRI_IMAGE_DIR', __DIR__ . '/photos')),
        Config::get('NUTRI_LANDING_BASE_URL')
    );

    $router->route($message);
    logWebhookEvent('routed', ['type' => $message->type, 'chatId' => $message->chatId, 'body' => $message->body, 'idMessage' => $message->idMessage]);

    http_response_code(200);
    echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    logWebhookEvent('error', ['message' => $e->getMessage()]);
    error_log('Nutri Helper webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno procesando el mensaje.'], JSON_UNESCAPED_UNICODE);
}

function logWebhookBody(string $rawBody): void
{
    $maxLineLength = 4000;

    $truncated = strlen($rawBody) > $maxLineLength
        ? substr($rawBody, 0, $maxLineLength) . '…(truncado)'
        : $rawBody;

    appendWebhookLog('[webhook] ' . date('c') . ' ' . $truncated);
}

/**
 * @param array<string,mixed> $data
 */
function logWebhookEvent(string $label, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    appendWebhookLog('[webhook:' . $label . '] ' . date('c') . ' ' . ($json === false ? '{}' : $json));
}

function appendWebhookLog(string $line): void
{
    $logsDir = __DIR__ . '/logs';
    $logPath = $logsDir . '/webhook.log';
    $maxBytes = 5 * 1024 * 1024;

    if (!is_dir($logsDir) && !mkdir($logsDir, 0775, true) && !is_dir($logsDir)) {
        error_log($line);
        return;
    }

    if (is_file($logPath) && filesize($logPath) > $maxBytes) {
        @rename($logPath, $logPath . '.1');
    }

    file_put_contents($logPath, $line . "\n", FILE_APPEND | LOCK_EX);
}

function resolveWebhookToken(): string
{
    $queryToken = (string)($_GET['token'] ?? '');
    if ($queryToken !== '') {
        return $queryToken;
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    return is_string($authorization) ? trim($authorization) : '';
}

function isValidWebhookToken(string $expectedToken, string $providedToken): bool
{
    if ($providedToken === '') {
        return false;
    }

    if (hash_equals($expectedToken, $providedToken)) {
        return true;
    }

    foreach (['Bearer ', 'Basic '] as $prefix) {
        if (hash_equals($prefix . $expectedToken, $providedToken)) {
            return true;
        }
    }

    return false;
}

function isIgnorableWebhookPayload(array $payload): bool
{
    $typeWebhook = (string)($payload['typeWebhook'] ?? $payload['body']['typeWebhook'] ?? '');

    return $typeWebhook !== '' && $typeWebhook !== 'incomingMessageReceived';
}
