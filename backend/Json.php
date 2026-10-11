<?php

declare(strict_types=1);

namespace MocnyDrop;

/**
 * backend/Json.php
 *
 * Wspólne helpersy HTTP dla endpointów w api/.
 *
 * Każdy endpoint wygląda dzięki temu tak samo: ustawia metodę, czyta body,
 * woła serwis domenowy i zwraca JSON. Obsługa błędów jest w jednym miejscu,
 * więc frontend zawsze dostaje ten sam kształt odpowiedzi:
 *
 *   sukces: { "success": true, ...dane }
 *   błąd:   { "success": false, "error": "...", "kod": "..." }
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Json
{
    /** @param array<string, mixed> $payload */
    public static function respond(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        // JSON_UNESCAPED_UNICODE — nazwy ryb są po polsku i mają pozostać
        // czytelne w odpowiedzi zamiast \u0142. PRETTY_PRINT ułatwia
        // debugowanie curl-em na etapie budowy frontendu.
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        exit;
    }

    /** @param array<string, mixed> $data */
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

    /**
     * Czyta body żądania jako tablicę.
     *
     * Puste body to pusta tablica (nie błąd) — część endpointów GET/POST
     * nie wymaga żadnych pól.
     *
     * @return array<string, mixed>
     */
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

    /** @param array<string, mixed> $body */
    public static function stringField(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * Pole liczbowe z body. Rzuca 400, gdy wartość nie jest liczbą —
     * dzięki temu `case_id: "abc"` nie zamienia się po cichu w 0.
     *
     * @param array<string, mixed> $body
     */
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

    /**
     * CORS dla środowiska developerskiego.
     *
     * Celowo odblokowujemy TYLKO localhost/127.0.0.1 z dowolnym portem —
     * projekt odpala się z `php -S localhost:8000` albo z Live Server w VSCode,
     * a to dwa różne porty. Gwiazdka (*) otworzyłaby API na cały internet.
     */
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

    /** Obsługuje preflight OPTIONS i pilnuje dozwolonych metod. */
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
