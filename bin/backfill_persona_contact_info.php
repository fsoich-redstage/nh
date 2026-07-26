<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Http\GreenApiClient;
use NutriHelper\Repository\PersonaRepository;

Config::load(__DIR__ . '/../.env');

/**
 * One-off backfill for people who onboarded before two bugs were fixed:
 * - persona.foto was being filled with the WhatsApp pushname text, never
 *   with the actual profile picture URL Green API's GetContactInfo returns.
 * - pushname itself wasn't stored anywhere at all.
 *
 * Re-fetches GetContactInfo for every existing persona and fills in
 * name/shortname/foto (profilePicUrl)/pushname (if that column exists) with
 * whatever Green API actually has today. Only overwrites a field when Green
 * API returns a non-empty value for it — a temporary gap in the response
 * never blanks out data a persona already had.
 *
 * Run manually once after applying the onboarding fix:
 *   php bin/backfill_persona_contact_info.php
 *
 * Safe to re-run — it's idempotent (just re-fetches and re-applies the same
 * non-empty fields each time).
 */
$conn = Database::connect(Config::database());
$greenApi = new GreenApiClient(Config::greenApi());
$personas = new PersonaRepository($conn);

$all = $personas->findAllPersonas();

$updated = 0;
$noNewData = 0;
$failed = 0;

foreach ($all as $persona) {
    $number = (string)$persona['number'];
    if ($number === '') {
        continue;
    }
    $chatId = str_ends_with($number, '@c.us') ? $number : $number . '@c.us';

    $contact = $greenApi->getContactInfo($chatId);
    if ($contact === null) {
        $failed++;
        error_log("[backfill] no se pudo obtener GetContactInfo para {$number}");
        continue;
    }

    if ($personas->updateContactInfo($chatId, $contact)) {
        $updated++;
    } else {
        $noNewData++;
    }

    // Green API rate-limits GetContactInfo per instance tier — a small pause
    // keeps a large backfill from tripping it. Adjust if your tier allows more.
    usleep(300_000);
}

echo json_encode([
    'ok'          => true,
    'total'       => count($all),
    'updated'     => $updated,
    'no_new_data' => $noNewData,
    'failed'      => $failed,
], JSON_UNESCAPED_UNICODE), PHP_EOL;
