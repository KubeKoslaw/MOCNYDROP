<?php

declare(strict_types=1);

namespace MocnyDrop;

final class Json
{

    public static function respond(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            error_log('[MOCNYDROP] Błąd kodowania odpowiedzi JSON: ' . $e->getMessage());

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-store');
            }

            echo '{"success":false,"error":"Błąd kodowania odpowiedzi.","kod":"encoding_error"}';

            exit;
        }

        echo $json;

        exit;
    }

    public static function success(array $data = [], int $status = 200): never
    {
        self::respond(array_merge(['success' => true], $data), $status);
    }

    public static function fail(string $message, string $kod = 'error', int $status = 400): never
    {
        self::respond(['success' => false, 'error' => $message, 'kod' => $kod], $status);
    }

    public static function failWith(ApiException $e): never
    {
        self::fail($e->getMessage(), $e->errorCode(), $e->httpStatus());
    }

    public static function readBody(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw ApiException::badRequest('Body żądania musi być obiektem JSON.', 'invalid_json');
        }

        return $decoded;
    }

    public static function stringField(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function intField(array $body, string $key): int
    {
        $value = $body[$key] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw ApiException::badRequest('Brak wymaganego pola: ' . $key, 'missing_field');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw ApiException::badRequest('Pole ' . $key . ' musi być liczbą całkowitą.', 'invalid_field');
    }

    public static function enableCors(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        if ($origin !== '' && preg_match('~^https?://(localhost|127\.0\.0\.1)(:\d+)?$~i', $origin) === 1) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }

        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }

    public static function requireMethod(string ...$methods): void
    {
        $current = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($current === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if (!in_array($current, $methods, true)) {
            self::fail(
                'Metoda ' . $current . ' nie jest obsługiwana. Dozwolone: ' . implode(', ', $methods) . '.',
                'method_not_allowed',
                405
            );
        }
    }
}
