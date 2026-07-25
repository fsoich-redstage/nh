<?php
declare(strict_types=1);

namespace NutriHelper\Db;

final class Database
{
    private static ?\PDO $connection = null;

    /**
     * @param array{host:string,user:string,password:string,database:string} $config
     */
    public static function connect(array $config): \PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['database']);

        self::$connection = new \PDO($dsn, $config['user'], $config['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$connection;
    }

    public static function tableHasColumn(\PDO $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);

        return (bool)$stmt->fetchColumn();
    }
}
