<?php

declare(strict_types=1);

namespace MocnyDrop;

use PDO;

final class LogAudytowy
{

    public int $id;

    public function __construct(
        public readonly string $akcja,
        public string $czas,
        public readonly int $uzytkownikId = 0,
        public readonly string $szczegoly = '',
        int $id = 0,
        private ?PDO $db = null,
    ) {
        $this->id = $id;
    }

    public function zapisz(): void
    {
        $db = $this->polaczenie();

        $stmt = $db->prepare(
            'INSERT INTO log_audytowy (user_id, akcja, szczegoly, czas) VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $this->uzytkownikId > 0 ? $this->uzytkownikId : null,
            $this->akcja,
            $this->szczegoly,
            $this->czas !== '' ? $this->czas : date('Y-m-d H:i:s'),
        ]);

        $this->id = (int) $db->lastInsertId();
    }

    public static function wpis(
        string $akcja,
        int $uzytkownikId = 0,
        string $szczegoly = '',
        ?PDO $pdo = null
    ): self {
        $log = new self($akcja, date('Y-m-d H:i:s'), $uzytkownikId, $szczegoly, 0, $pdo);

        $log->zapisz();

        return $log;
    }

    public static function ostatnie(int $limit = 50, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::connection();

        $limit = max(1, min(500, $limit));

        $stmt = $db->prepare(
            'SELECT l.id, l.user_id, l.akcja, l.szczegoly, l.czas, u.login
             FROM log_audytowy l
             LEFT JOIN uzytkownicy u ON u.id = l.user_id
             ORDER BY l.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'login' => $row['login'] === null ? null : (string) $row['login'],
                'akcja' => (string) $row['akcja'],
                'szczegoly' => (string) $row['szczegoly'],
                'czas' => (string) $row['czas'],
            ];
        }

        return $out;
    }

    private function polaczenie(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
