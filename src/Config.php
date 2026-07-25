<?php
declare(strict_types=1);

namespace NutriHelper;

/**
 * Minimal .env loader and required-config accessor. No hardcoded fallbacks:
 * a missing required key throws instead of silently degrading to a default.
 */
final class Config
{
    /** @var array<string,string>|null */
    private static ?array $values = null;

    public static function load(string $envFile): void
    {
        self::$values = [];

        if (!is_file($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip matching surrounding quotes, if present.
            if (strlen($value) >= 2 && (
                ($value[0] === '"' && str_ends_with($value, '"')) ||
                ($value[0] === "'" && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }

    public static function get(string $key): string
    {
        $value = self::$values[$key] ?? (getenv($key) ?: null);

        if ($value === null || $value === '') {
            throw new \RuntimeException("Falta configurar la variable de entorno '{$key}'.");
        }

        return $value;
    }

    public static function getOptional(string $key, string $default = ''): string
    {
        $value = self::$values[$key] ?? (getenv($key) ?: null);

        return $value !== null && $value !== '' ? $value : $default;
    }

    /**
     * @return array{apiUrl:string,idInstance:string,apiToken:string}
     */
    public static function greenApi(): array
    {
        return [
            'apiUrl'     => self::get('GREEN_API_URL'),
            'idInstance' => self::get('GREEN_API_INSTANCE_ID'),
            'apiToken'   => self::get('GREEN_API_TOKEN'),
        ];
    }

    /**
     * @return array{host:string,user:string,password:string,database:string}
     */
    public static function database(): array
    {
        return [
            'host'     => self::get('DB_HOST'),
            'user'     => self::get('DB_USER'),
            'password' => self::get('DB_PASSWORD'),
            'database' => self::get('DB_NAME'),
        ];
    }
}
