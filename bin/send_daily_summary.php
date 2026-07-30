<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Domain\DayChartRenderer;
use NutriHelper\Domain\EventDeduplicator;
use NutriHelper\Domain\MealWindows;
use NutriHelper\Http\GreenApiClient;
use NutriHelper\Http\OpenAiClient;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;

Config::load(__DIR__ . '/../.env');

// Meant to run via cron (hourly is fine); only actually sends anything during
// the configured end-of-day hour, and only once per calendar day even if the
// cron misfires more than once.
$tzLocal = new DateTimeZone('America/Argentina/Buenos_Aires');
$now = new DateTime('now', $tzLocal);
$targetHour = (int)Config::getOptional('NUTRI_DAILY_SUMMARY_HOUR', '22');

if ((int)$now->format('G') !== $targetHour) {
    echo json_encode(['ok' => true, 'sent' => 0, 'reason' => "fuera de la hora configurada ({$targetHour}hs)"], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}

$dedup = new EventDeduplicator(__DIR__ . '/../storage/locks', 23 * 3600);
if (!$dedup->claim('daily-summary-' . $now->format('Y-m-d'))) {
    echo json_encode(['ok' => true, 'sent' => 0, 'reason' => 'ya se envió el resumen de hoy'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}

$conn = Database::connect(Config::database());
$greenApi = new GreenApiClient(Config::greenApi());
$openAi = new OpenAiClient(Config::get('OPENAI_API_KEY'));
$personas = new PersonaRepository($conn);
$nutrition = new NutritionRepository($conn);
$chartRenderer = new DayChartRenderer(
    Config::getOptional('NUTRI_IMAGE_DIR', __DIR__ . '/../photos'),
    rtrim(Config::get('NUTRI_LANDING_BASE_URL'), '/') . Config::getOptional('NUTRI_IMAGE_PUBLIC_PATH', '/photos')
);

$targets = $nutrition->findTodaysSummaryTargets();

$results = [];
foreach ($targets as $target) {
    $identifier = (string)$target['identifier'];
    $number = PersonaRepository::normalizePhone((string)$target['number']);
    if ($number === '') {
        continue;
    }
    $chatId = str_ends_with($number, '@c.us') ? $number : $number . '@c.us';

    $entries = $nutrition->fetchTodayEntriesForIdentifier($identifier);
    if ($entries === []) {
        continue;
    }

    $totals = ['calorias' => 0, 'proteinas' => 0, 'carbohidratos' => 0, 'grasas' => 0];
    $meals = [];
    foreach ($entries as $entry) {
        $totals['calorias'] += (int)($entry['calorias'] ?? 0);
        $totals['proteinas'] += (int)($entry['proteinas'] ?? 0);
        $totals['carbohidratos'] += (int)($entry['carbohidratos'] ?? 0);
        $totals['grasas'] += (int)($entry['grasas'] ?? 0);

        $hora = (new DateTime((string)($entry['datetime'] ?? 'now'), new DateTimeZone('UTC')))
            ->setTimezone($tzLocal)
            ->format('H:i');
        $hour = (int)substr($hora, 0, 2);
        // Prefer the meal type stored with the entry (accurate even for a
        // late text entry logged outside its natural window); fall back to
        // classifying by hour for rows from before that column existed.
        $comida = (string)($entry['comida'] ?? '') !== '' ? (string)$entry['comida'] : MealWindows::classifyHour($hour);

        $meals[] = [
            'comida'        => $comida,
            'hora'          => $hora,
            'descripcion'   => (string)($entry['descripcion'] ?? ''),
            'calorias'      => (int)($entry['calorias'] ?? 0),
            'proteinas'     => (int)($entry['proteinas'] ?? 0),
            'carbohidratos' => (int)($entry['carbohidratos'] ?? 0),
            'grasas'        => (int)($entry['grasas'] ?? 0),
            'consejo'       => (string)($entry['consejo_actual'] ?? ''),
        ];
    }

    try {
        $summary = $openAi->analyzeDaySummary($meals, $totals);

        $reply = [];
        if ($summary['resumen'] !== '') {
            $reply[] = '📅 Resumen de hoy: ' . $summary['resumen'];
        }
        $reply[] = "Totales: {$totals['calorias']} kcal · {$totals['proteinas']} g proteínas · "
            . "{$totals['carbohidratos']} g carbohidratos · {$totals['grasas']} g grasas";
        if ($summary['consejo'] !== '') {
            $reply[] = '👉 Para mañana: ' . $summary['consejo'];
        }

        $sendResult = $greenApi->sendMessage($chatId, implode("\n", $reply));

        // Best-effort chart attachment — never let a rendering/upload hiccup
        // block the text summary that already went out above.
        try {
            $chartUrl = $chartRenderer->render($identifier, $totals);
            $greenApi->sendFileByUrl($chatId, $chartUrl, 'resumen_' . $identifier . '.png', '📊 Tus macros de hoy');
        } catch (Throwable $e) {
            error_log('Nutri Helper: fallo generando chart diario para ' . $identifier . ': ' . $e->getMessage());
        }

        $results[] = ['to' => $chatId, 'identifier' => $identifier, 'status' => $sendResult['status']];
    } catch (Throwable $e) {
        error_log('Nutri Helper: fallo generando resumen diario para ' . $identifier . ': ' . $e->getMessage());
        $results[] = ['to' => $chatId, 'identifier' => $identifier, 'error' => $e->getMessage()];
    }
}

echo json_encode(['ok' => true, 'sent' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE), PHP_EOL;
