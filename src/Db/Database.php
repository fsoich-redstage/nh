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

        $lastException = null;

        foreach (self::buildDsns($config) as $dsn) {
            try {
                self::$connection = new \PDO($dsn, $config['user'], $config['password'], [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                return self::$connection;
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        if ($lastException instanceof \Throwable) {
            throw $lastException;
        }

        throw new \RuntimeException('No se pudo conectar a la base de datos.');
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

    /**
     * @param array{host:string,database:string} $config
     * @return string[]
     */
    private static function buildDsns(array $config): array
    {
        $database = $config['database'];
        $host = $config['host'];
        $dsns = [];

        if ($host !== '' && !str_ends_with($host, '.sock')) {
            $dsns[] = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);
        }

        $socket = self::inferSocketPath($host);
        if ($socket !== null) {
            $dsns[] = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $database);
        }

        return array_values(array_unique($dsns));
    }

    private static function inferSocketPath(string $host): ?string
    {
        if ($host !== '' && str_ends_with($host, '.sock') && file_exists($host)) {
            return $host;
        }

        if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        foreach (['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
