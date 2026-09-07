<?php

namespace App\Support;

use InvalidArgumentException;

final class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('Bytes vazios.');
        }

        if (trim($bytes, "\0") === '') {
            return str_repeat('1', strlen($bytes));
        }

        $digits = [0];
        foreach (array_values(unpack('C*', $bytes)) as $byte) {
            $carry = $byte;
            for ($i = 0, $count = count($digits); $i < $count; $i++) {
                $carry += $digits[$i] * 256;
                $digits[$i] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        $leadingZeroes = 0;
        for ($i = 0, $length = strlen($bytes); $i < $length && $bytes[$i] === "\0"; $i++) {
            $leadingZeroes++;
        }

        $result = str_repeat('1', $leadingZeroes);
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $result .= self::ALPHABET[$digits[$i]];
        }

        return $result;
    }

    /** @return non-empty-string */
    public static function decode(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Base58 vazio.');
        }

        $map = array_flip(str_split(self::ALPHABET));
        $bytes = [];

        foreach (str_split($value) as $character) {
            if (! array_key_exists($character, $map)) {
                throw new InvalidArgumentException('Caractere Base58 inválido.');
            }

            $carry = $map[$character];
            for ($i = 0, $count = count($bytes); $i < $count; $i++) {
                $carry += $bytes[$i] * 58;
                $bytes[$i] = $carry & 0xFF;
                $carry = intdiv($carry, 256);
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry = intdiv($carry, 256);
            }
        }

        foreach (str_split($value) as $character) {
            if ($character !== '1') {
                break;
            }
            $bytes[] = 0;
        }

        if ($bytes === []) {
            $bytes = [0];
        }

        return implode('', array_map(static fn (int $byte): string => chr($byte), array_reverse($bytes)));
    }
}
