<?php
declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Db\LegacySchemaMigrator;

Config::load(__DIR__ . '/.env');

header('Content-Type: application/json; charset=utf-8');

$token = Config::getOptional('MIGRATION_TOKEN', Config::webhookToken());
$provided = (string)($_GET['token'] ?? '');

if ($token === '' || !hash_equals($token, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::connect(Config::database());
    $statements = LegacySchemaMigrator::migrate($pdo);

    echo json_encode([
        'status' => 'ok',
        'applied' => $statements,
        'message' => $statements === [] ? 'Schema already up to date.' : 'Legacy schema migration complete.',
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Migration failed.',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
