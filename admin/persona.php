<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Http\AdminAuth;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;
use NutriHelper\View\AdminRenderer;

Config::load(__DIR__ . '/../.env');

AdminAuth::require();

header('Content-Type: text/html; charset=utf-8');

// Same sanitization as index.php: strip anything that isn't A-Z/0-9.
$identifier = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)($_GET['identifier'] ?? '')));

if ($identifier === '') {
    http_response_code(400);
    echo 'Falta ?identifier=';
    exit;
}

$conn = Database::connect(Config::database());
$personas = new PersonaRepository($conn);
$nutrition = new NutritionRepository($conn);

$persona = $personas->findByIdentifier($identifier);
if ($persona === null) {
    http_response_code(404);
    echo 'No existe ese identifier.';
    exit;
}

$entries = $nutrition->fetchEntriesForIdentifier($identifier);

echo (new AdminRenderer())->renderUserDetail(
    $persona,
    $entries,
    Config::getOptional('NUTRI_IMAGE_PUBLIC_PATH', '/photos')
);
