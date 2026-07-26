<?php
declare(strict_types=1);

namespace NutriHelper\Http;

use NutriHelper\Config;

/**
 * Minimal HTTP Basic Auth guard for the /admin panel — the only place in
 * this app that exposes every user's phone number, photos and meal history
 * at once, so it must never be reachable without credentials.
 *
 * Requires ADMIN_USERNAME and ADMIN_PASSWORD_HASH in .env (generate the hash
 * with `php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"`) —
 * refuses to serve anything if either is missing, same "no hardcoded
 * fallback" convention as the rest of this project's Config class.
 */
final class AdminAuth
{
    public static function require(): void
    {
        $username = Config::getOptional('ADMIN_USERNAME', '');
        $passwordHash = Config::getOptional('ADMIN_PASSWORD_HASH', '');

        if ($username === '' || $passwordHash === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Admin panel no configurado: falta ADMIN_USERNAME / ADMIN_PASSWORD_HASH en .env.';
            exit;
        }

        $providedUser = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
        $providedPass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');

        $userOk = hash_equals($username, $providedUser);
        $passOk = $providedPass !== '' && password_verify($providedPass, $passwordHash);

        if (!$userOk || !$passOk) {
            header('WWW-Authenticate: Basic realm="Nutri Helper Admin"');
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Acceso denegado.';
            exit;
        }
    }
}
