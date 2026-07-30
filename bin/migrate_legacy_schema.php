<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;

Config::load(__DIR__ . '/../.env');

$pdo = Database::connect(Config::database());

$statements = [];

if (!tableExists($pdo, 'persona')) {
    $statements[] = <<<'SQL'
CREATE TABLE persona (
    number BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL DEFAULT '',
    shortname VARCHAR(191) NOT NULL DEFAULT '',
    pushname VARCHAR(191) NOT NULL DEFAULT '',
    foto VARCHAR(191) NOT NULL DEFAULT '',
    identifier VARCHAR(16) NOT NULL,
    tipo VARCHAR(32) NOT NULL DEFAULT 'default',
    campo1 TINYINT(1) NOT NULL DEFAULT 0,
    campo2 TINYINT(1) NOT NULL DEFAULT 0,
    water_frequency SMALLINT UNSIGNED NULL DEFAULT NULL,
    age_range VARCHAR(16) NULL DEFAULT NULL,
    weight_range VARCHAR(16) NULL DEFAULT NULL,
    onboarding_step VARCHAR(24) NOT NULL DEFAULT 'done',
    pending_meal_reminder VARCHAR(16) NULL DEFAULT NULL,
    pending_water_poll TINYINT(1) NOT NULL DEFAULT 0,
    pending_text_meal VARCHAR(16) NULL DEFAULT NULL,
    pending_backdate_step VARCHAR(24) NULL DEFAULT NULL,
    pending_backdate_date DATE NULL DEFAULT NULL,
    pending_backdate_meal VARCHAR(16) NULL DEFAULT NULL,
    PRIMARY KEY (number),
    UNIQUE KEY uniq_identifier (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
} else {
    if (Database::tableHasColumn($pdo, 'persona', 'setting') && !Database::tableHasColumn($pdo, 'persona', 'water_frequency')) {
        $statements[] = 'ALTER TABLE persona CHANGE setting water_frequency SMALLINT UNSIGNED NULL DEFAULT NULL';
        $statements[] = 'UPDATE persona SET water_frequency = NULL WHERE water_frequency = 0';
    }

    addColumnIfMissing($pdo, $statements, 'persona', 'pushname', "ADD COLUMN pushname VARCHAR(191) NOT NULL DEFAULT ''");
    addColumnIfMissing($pdo, $statements, 'persona', 'water_frequency', 'ADD COLUMN water_frequency SMALLINT UNSIGNED NULL DEFAULT NULL');
    addColumnIfMissing($pdo, $statements, 'persona', 'age_range', 'ADD COLUMN age_range VARCHAR(16) NULL DEFAULT NULL');
    addColumnIfMissing($pdo, $statements, 'persona', 'weight_range', 'ADD COLUMN weight_range VARCHAR(16) NULL DEFAULT NULL');
    addColumnIfMissing($pdo, $statements, 'persona', 'onboarding_step', "ADD COLUMN onboarding_step VARCHAR(24) NOT NULL DEFAULT 'done'");
    addColumnIfMissing($pdo, $statements, 'persona', 'pending_meal_reminder', 'ADD COLUMN pending_meal_reminder VARCHAR(16) NULL DEFAULT NULL');
    addColumnIfMissing($pdo, $statements, 'persona', 'pending_water_poll', 'ADD COLUMN pending_water_poll TINYINT(1) NOT NULL DEFAULT 0');
    addColumnIfMissing($pdo, $statements, 'persona', 'pending_text_meal', 'ADD COLUMN pending_text_meal VARCHAR(16) NULL DEFAULT NULL');
    addColumnIfMissing($pdo, $statements, 'persona', 'pending_backdate_step', 'ADD COLUMN pending_backdate_step VARCHAR(24) NULL DEFAULT NULL');
    addColumnIfMissing($pdo, $statements, 'persona', 'pending_backdate_date', 'ADD COLUMN pending_backdate_date DATE NULL DEFAULT NULL');
    addColumnIfMissing($pdo, $statements, 'persona', 'pending_backdate_meal', 'ADD COLUMN pending_backdate_meal VARCHAR(16) NULL DEFAULT NULL');
}

if (!tableExists($pdo, 'nutri')) {
    $statements[] = <<<'SQL'
CREATE TABLE nutri (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    foto VARCHAR(191) NOT NULL,
    descripcion TEXT NOT NULL,
    datetime DATETIME NOT NULL,
    identifier VARCHAR(16) NOT NULL,
    calorias INT NOT NULL DEFAULT 0,
    proteinas INT NOT NULL DEFAULT 0,
    grasas INT NOT NULL DEFAULT 0,
    carbohidratos INT NOT NULL DEFAULT 0,
    calorias_label VARCHAR(64) NOT NULL DEFAULT '',
    proteinas_label VARCHAR(64) NOT NULL DEFAULT '',
    grasas_label VARCHAR(64) NOT NULL DEFAULT '',
    carbohidratos_label VARCHAR(64) NOT NULL DEFAULT '',
    source VARCHAR(32) NOT NULL DEFAULT '',
    consejo_actual TEXT NULL,
    comida VARCHAR(16) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_identifier_datetime (identifier, datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
} else {
    addColumnIfMissing($pdo, $statements, 'nutri', 'consejo_actual', 'ADD COLUMN consejo_actual TEXT NULL');
    addColumnIfMissing($pdo, $statements, 'nutri', 'comida', 'ADD COLUMN comida VARCHAR(16) NULL DEFAULT NULL');
    addIndexIfMissing($pdo, $statements, 'nutri', 'idx_identifier_datetime', 'ADD KEY idx_identifier_datetime (identifier, datetime)');
}

if ($statements === []) {
    echo "Schema already up to date.\n";
    exit(0);
}

foreach ($statements as $sql) {
    $pdo->exec($sql);
    echo "[ok] {$sql}\n";
}

echo "Legacy schema migration complete.\n";

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?
         LIMIT 1'
    );
    $stmt->execute([$table]);

    return (bool)$stmt->fetchColumn();
}

function addColumnIfMissing(PDO $pdo, array &$statements, string $table, string $column, string $ddl): void
{
    if (!Database::tableHasColumn($pdo, $table, $column)) {
        $statements[] = sprintf('ALTER TABLE %s %s', $table, $ddl);
    }
}

function addIndexIfMissing(PDO $pdo, array &$statements, string $table, string $indexName, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $indexName]);

    if (!$stmt->fetchColumn()) {
        $statements[] = sprintf('ALTER TABLE %s %s', $table, $ddl);
    }
}
