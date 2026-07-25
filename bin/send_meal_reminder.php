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
 * Runs hourly via cron. For each active person and each of the 4 meals, asks
 * (once, via poll) "did you forget?" — but only during that meal's own hour
 * window, and only right around when THAT PERSON usually logs it (their
 * historical average hour for that meal, or a sensible default if they don't
 * have history yet). At most one reminder per person per meal per day.
 *
 * Only runs for people who logged at least one meal YESTERDAY — someone who's
 * gone quiet doesn't get chased with reminders.
 */
$tzLocal = new DateTimeZone('America/Argentina/Buenos_Aires');
$now = new DateTime('now', $tzLocal);
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

    if (!$nutrition->hadEntriesYesterday($identifier)) {
        continue; // no registró nada ayer: no lo perseguimos con recordatorios
    }

    $chatId = str_ends_with($number, '@c.us') ? $number : $number . '@c.us';

    foreach (MealWindows::all() as $mealType) {
        if ($currentHour < MealWindows::startHour($mealType) || $currentHour >= MealWindows::endHour($mealType)) {
            continue; // outside this meal's own window entirely
        }

        if ($nutrition->hasMealTypeToday($identifier, $mealType)) {
            continue; // already logged
        }

        $averageHour = $nutrition->findAverageMealHour($identifier, $mealType);
        $targetHour = $averageHour !== null
            ? max(MealWindows::startHour($mealType), min(MealWindows::endHour($mealType) - 1, (int)round($averageHour)))
            : MealWindows::defaultHour($mealType);

        if ($currentHour !== $targetHour) {
            continue; // not their moment yet (or already passed) this hour
        }

        if (!$dedup->claim("meal-reminder-{$identifier}-{$mealType}-{$today}")) {
            continue; // already asked about this meal today
        }

        $options = ['No comí', MealWindows::skipOptionLabel($mealType), 'Comí, me olvidé de mandar la foto'];
        $lowerMeal = mb_strtolower($mealType, 'UTF-8');

        $sendResult = $greenApi->sendPoll(
            $chatId,
            "🍽️ ¿Te olvidaste de mandar tu {$mealType}? Todavía no vi que registraras tu {$lowerMeal} de hoy.",
            $options,
            false
        );

        if ($sendResult['status'] < 400) {
            $personas->setPendingMealReminder($chatId, $mealType);
        }

        $results[] = [
            'to'       => $chatId,
            'meal'     => $mealType,
            'status'   => $sendResult['status'],
        ];
    }
}

echo json_encode(['ok' => true, 'sent' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE), PHP_EOL;
