<?php

namespace App\Domain\Atendimentos\Support;

use InvalidArgumentException;

final class AtendimentoCursor
{
    /** @return array{data:string,id:int} */
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
            || ! isset($decoded['data'], $decoded['id'])
            || ! is_string($decoded['data'])
            || ! is_int($decoded['id'])
            || $decoded['data'] === ''
            || $decoded['id'] < 1) {
            throw new InvalidArgumentException('Cursor de atendimentos inválido.');
        }

        return [
            'data' => $decoded['data'],
            'id' => $decoded['id'],
        ];
    }

    public static function encode(string $data, int $id): string
    {
        $json = json_encode([
            'data' => $data,
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
