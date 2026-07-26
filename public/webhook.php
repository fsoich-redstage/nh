<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

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

Config::load(__DIR__ . '/../.env');

header('Content-Type: application/json; charset=utf-8');

$expectedToken = Config::webhookToken();
if ($expectedToken === '') {
    // Refuse to run unauthenticated rather than silently accepting anyone's
    // POSTs — GREEN_API_WEBHOOK_TOKEN must be set once GREEN_API_WEBHOOK_TOKEN
    // is configured, see .env.example.
    http_response_code(500);
    echo json_encode(['error' => 'Webhook no configurado: falta GREEN_API_WEBHOOK_TOKEN.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$providedToken = (string)($_GET['token'] ?? '');
if (!hash_equals($expectedToken, $providedToken)) {
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

$message = (new WebhookPayloadNormalizer())->normalize($payload);

if ($message === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido o incompleto.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (str_ends_with($message->chatId, '@g.us')) {
    // Nutri Helper is a 1:1 bot — a group JID would otherwise be treated as a
    // regular persona number (normalizePhone() strips the @g.us suffix down
    // to digits) and silently corrupt onboarding/meal data for that "number".
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Chat grupal, ignorado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deduplicator = new EventDeduplicator(__DIR__ . '/../storage/locks');
if (!$deduplicator->claim($message->idMessage)) {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Evento duplicado, ignorado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $greenApi = new GreenApiClient(Config::greenApi());
    $openAi = new OpenAiClient(Config::get('OPENAI_API_KEY'));
    $conn = Database::connect(Config::database());

    // Best-effort read receipt so the sender sees the double-tick right away
    // instead of only ~5-10s later when the OpenAI analysis reply arrives.
    try {
        $greenApi->readChat($message->chatId, $message->idMessage);
    } catch (\Throwable) {
        // Non-critical — never block message processing on this.
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

    http_response_code(200);
    echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('Nutri Helper webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno procesando el mensaje.'], JSON_UNESCAPED_UNICODE);
}

/**
 * Appends a truncated, timestamped line to logs/webhook.log, rotating the
 * file (keeping one previous copy) once it grows past ~5MB — the raw body
 * includes phone numbers and meal descriptions, so this is debug-only
 * evidence, not a permanent audit trail.
 */
function logWebhookBody(string $rawBody): void
{
    $logPath = __DIR__ . '/../logs/webhook.log';
    $maxBytes = 5 * 1024 * 1024;
    $maxLineLength = 4000;

    if (is_file($logPath) && filesize($logPath) > $maxBytes) {
        @rename($logPath, $logPath . '.1');
    }

    $truncated = strlen($rawBody) > $maxLineLength
        ? substr($rawBody, 0, $maxLineLength) . '…(truncado)'
        : $rawBody;

    error_log('[webhook] ' . date('c') . ' ' . $truncated . "\n", 3, $logPath);
}
