<?php
namespace App\Libraries;

class JwtContext
{
    private static ?array $payload = null;

    public static function set(array $payload): void
    {
        self::$payload = $payload;
    }

    public static function get(): array
    {
        return self::$payload ?? [];
    }
}
