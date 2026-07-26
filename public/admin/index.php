<?php
declare(strict_types=1);

require __DIR__ . '/../../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Http\AdminAuth;
use NutriHelper\Repository\PersonaRepository;
use NutriHelper\View\AdminRenderer;

Config::load(__DIR__ . '/../../.env');

AdminAuth::require();

header('Content-Type: text/html; charset=utf-8');

$conn = Database::connect(Config::database());
$personas = new PersonaRepository($conn);

echo (new AdminRenderer())->renderUserList($personas->findAllWithStats());
