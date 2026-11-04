<?php

declare(strict_types=1);

namespace MocnyDrop;

final class Config
{

    private const ICONS_URL_PREFIX = 'assets/icons/';

    public static function rootDir(): string
    {
        return dirname(__DIR__);
    }

    public static function databaseDir(): string
    {
        return self::rootDir() . '/database';
    }

    public static function schemaFile(): string
    {
        return self::databaseDir() . '/schema.sql';
    }
    public static function databaseOptions(): array
    {
        return ['driver' => 'sqlite'];
    }

    public static function databaseFile(): string
    {
        return self::databaseDir() . '/mocnydrop.sqlite';
    }

    public static function seedDir(): string
    {
        return self::databaseDir() . '/seed';
    }

    public static function iconsDir(): string
    {
        return self::rootDir() . '/assets/icons';
    }

    public static function iconUrl(string $fileName): string
    {
        $fileName = basename(trim($fileName));

        return $fileName === '' ? '' : self::ICONS_URL_PREFIX . $fileName;
    }

    public static function iconExists(string $fileName): bool
    {
        $fileName = basename(trim($fileName));

        return $fileName !== '' && is_file(self::iconsDir() . '/' . $fileName);
    }
}
