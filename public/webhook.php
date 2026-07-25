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

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?? '', true);

error_log('[webhook] ' . date('c') . ' ' . ($rawBody ?: '(empty body)') . "\n", 3, __DIR__ . '/../logs/webhook.log');

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

    $router = new MessageRouter(
        $greenApi,
        $openAi,
        new PersonaRepository($conn),
        new NutritionRepository($conn),
        new NutritionAnalysisParser(),
        new ImageStore(Config::getOptional('NUTRI_IMAGE_DIR', __DIR__ . '/../storage/images')),
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
