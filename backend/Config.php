<?php

declare(strict_types=1);

namespace MocnyDrop;

/**
 * backend/Config.php
 *
 * Ścieżki i parametry backendu w jednym miejscu.
 *
 * WAŻNE dla zespołu: ikony ryb leżą teraz w `assets/icons/`. Gdy @THOPA174
 * przeniesie frontend do `public/`, wystarczy podmienić ICONS_DIR i
 * ICONS_URL_PREFIX poniżej — reszta kodu i cała baza nic o tym nie wiedzą.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class Config
{
    /** Prefiks URL ikony względem dokumentu głównego serwera. */
    private const ICONS_URL_PREFIX = 'assets/icons/';

    /** Katalog główny projektu — ten, w którym leżą README.md i assets/. */
    public static function rootDir(): string
    {
        return dirname(__DIR__);
    }

    public static function databaseDir(): string
    {
        return self::rootDir() . '/database';
    }

    /** Plik ze schematem — importowany też przez CI do bazy w pamięci. */
    public static function schemaFile(): string
    {
        return self::databaseDir() . '/schema.sql';
    }

    /** Plik bazy. Jest w .gitignore, więc każdy tworzy go lokalnie. */
    public static function databaseFile(): string
    {
        return self::databaseDir() . '/mocnydrop.sqlite';
    }

    /**
     * Katalog z danymi startowymi.
     * Należy do @moxmar26 (patrz CODEOWNERS) — backend tylko go czyta.
     */
    public static function seedDir(): string
    {
        return self::databaseDir() . '/seed';
    }

    /** Katalog z ikonami ryb na dysku. */
    public static function iconsDir(): string
    {
        return self::rootDir() . '/assets/icons';
    }

    /**
     * Zamienia nazwę pliku ikony na ścieżkę URL zwracaną frontendowi.
     * basename() odcina wszelkie "../" — do bazy trafiają dane od zespołu,
     * ale nie ma powodu ufać im bardziej niż to konieczne.
     */
    public static function iconUrl(string $fileName): string
    {
        $fileName = basename(trim($fileName));

        return $fileName === '' ? '' : self::ICONS_URL_PREFIX . $fileName;
    }

    /** Sprawdza, czy ikona faktycznie istnieje — wyłapuje literówki w seedzie. */
    public static function iconExists(string $fileName): bool
    {
        $fileName = basename(trim($fileName));

        return $fileName !== '' && is_file(self::iconsDir() . '/' . $fileName);
    }
}
