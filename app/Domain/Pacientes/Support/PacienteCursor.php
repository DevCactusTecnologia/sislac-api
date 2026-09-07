<?php

namespace App\Domain\Pacientes\Support;

use InvalidArgumentException;

final class PacienteCursor
{
    /** @return array{updated_at:string,id:int} */
    public static function decode(string $cursor): array
    {
        $normalized = strtr($cursor, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($normalized, true);
        $decoded = $json === false ? null : json_decode($json, true);

        if (! is_array($decoded)
            || ! isset($decoded['updated_at'], $decoded['id'])
            || ! is_string($decoded['updated_at'])
            || ! is_int($decoded['id'])
            || $decoded['updated_at'] === ''
            || $decoded['id'] < 1) {
            throw new InvalidArgumentException('Cursor de pacientes inválido.');
        }

        return [
            'updated_at' => $decoded['updated_at'],
            'id' => $decoded['id'],
        ];
    }

    public static function encode(string $updatedAt, int $id): string
    {
        $json = json_encode([
            'updated_at' => $updatedAt,
            'id' => $id,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function isValid(string $cursor): bool
    {
        try {
            self::decode($cursor);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
