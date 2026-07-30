<?php
declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;
use NutriHelper\View\LandingRenderer;

Config::load(__DIR__ . '/.env');

header('Content-Type: text/html; charset=utf-8');

$renderer = new LandingRenderer();

// Same sanitization as production: strip anything that isn't A-Z/0-9, case-insensitive.
$identifier = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)($_GET['identifier'] ?? '')));

if ($identifier === '') {
    echo $renderer->renderPromo(Config::get('NUTRI_BOT_WHATSAPP_LINK'));
    exit;
}

$conn = Database::connect(Config::database());
$nutrition = new NutritionRepository($conn);
$entries = $nutrition->fetchEntriesForIdentifier($identifier);

if ($entries === []) {
    // Covers both "identifier doesn't exist" and "exists but has no meals yet" —
    // production shows the same promotional landing for either case.
    echo $renderer->renderPromo(Config::get('NUTRI_BOT_WHATSAPP_LINK'));
    exit;
}

$personas = new PersonaRepository($conn);
$headerName = $personas->findDisplayNameByIdentifier($identifier);

echo $renderer->renderHistory(
    $headerName,
    $identifier,
    $entries,
    Config::getOptional('NUTRI_IMAGE_PUBLIC_PATH', '/photos')
);
