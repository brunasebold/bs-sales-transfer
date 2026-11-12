<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

/**
 * Keeps binary column values intact inside a UTF-8 JSON line.
 */
final class ValueCodec
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function encodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = self::encode($value);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = self::decode($value);
        }

        return $row;
    }

    public static function encode(mixed $value): mixed
    {
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return ['__b64' => base64_encode($value)];
        }

        return $value;
    }

    public static function decode(mixed $value): mixed
    {
        if (!is_array($value) || count($value) !== 1 || !isset($value['__b64']) || !is_string($value['__b64'])) {
            return $value;
        }
        $decoded = base64_decode($value['__b64'], true);

        return $decoded === false ? $value['__b64'] : $decoded;
    }
}
