<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;
use NutriHelper\View\LandingRenderer;

Config::load(__DIR__ . '/../.env');

header('Content-Type: text/html; charset=utf-8');

$renderer = new LandingRenderer();
$identifier = trim((string)($_GET['identifier'] ?? ''));

if ($identifier === '' || !PersonaRepository::isWellFormedIdentifier($identifier)) {
    http_response_code(404);
    echo $renderer->renderInvalidIdentifier();
    exit;
}

$conn = Database::connect(Config::database());
$personas = new PersonaRepository($conn);

if (!$personas->identifierExists($identifier)) {
    http_response_code(404);
    echo $renderer->renderInvalidIdentifier();
    exit;
}

$nutrition = new NutritionRepository($conn);
$entries = $nutrition->fetchEntriesForIdentifier($identifier);

http_response_code(200);
echo $renderer->render($identifier, $entries);
