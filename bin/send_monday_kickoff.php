<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Domain\EventDeduplicator;
use NutriHelper\Domain\MealWindows;
use NutriHelper\Http\GreenApiClient;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;

Config::load(__DIR__ . '/../.env');

/**
 * Runs hourly via cron, but only ever does anything on Mondays. For every
 * active person who logged at least one meal last week, sends a single
 * "let's keep it up this week" nudge — timed to the hour when they usually
 * register breakfast (their historical average, rounded; a sensible default
 * if they don't have breakfast history yet).
 */
$tzLocal = new DateTimeZone('America/Argentina/Buenos_Aires');
$now = new DateTime('now', $tzLocal);

if ((int)$now->format('N') !== 1) {
    echo json_encode(['ok' => true, 'sent' => 0, 'reason' => 'hoy no es lunes'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}

$currentHour = (int)$now->format('G');
$today = $now->format('Y-m-d');

$conn = Database::connect(Config::database());
$greenApi = new GreenApiClient(Config::greenApi());
$personas = new PersonaRepository($conn);
$nutrition = new NutritionRepository($conn);
$dedup = new EventDeduplicator(__DIR__ . '/../storage/locks', 23 * 3600);

$results = [];

foreach ($personas->findActivePersonas() as $persona) {
    $identifier = (string)$persona['identifier'];
    $number = PersonaRepository::normalizePhone((string)$persona['number']);
    if ($number === '') {
        continue;
    }

    if (!$nutrition->hadEntriesLastWeek($identifier)) {
        continue; // no registró nada la semana pasada: no le mandamos el envión de arranque
    }

    $averageBreakfastHour = $nutrition->findAverageMealHour($identifier, 'DESAYUNO');
    $targetHour = $averageBreakfastHour !== null
        ? (int)round($averageBreakfastHour)
        : MealWindows::defaultHour('DESAYUNO');
    $targetHour = max(0, min(23, $targetHour));

    if ($currentHour !== $targetHour) {
        continue;
    }

    if (!$dedup->claim("monday-kickoff-{$identifier}-{$today}")) {
        continue; // ya se le mandó el mensaje de este lunes
    }

    $chatId = str_ends_with($number, '@c.us') ? $number : $number . '@c.us';

    $sendResult = $greenApi->sendMessage(
        $chatId,
        '🚀 ¡Arrancamos la semana! La semana pasada registraste tus comidas — dale que se puede, '
        . 'seguí mandándome tus 4 comidas del día para mantener el hábito.'
    );

    $results[] = ['to' => $chatId, 'identifier' => $identifier, 'status' => $sendResult['status']];
}

echo json_encode(['ok' => true, 'sent' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE), PHP_EOL;
