<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures;

/**
 * Codes and slugs of the taxons and products generated from a dataset.
 */
final class Codes
{
    /**
     * Lowercase ASCII words: matches "Thé vert", "the-vert" and "THÉ VERT 🍵".
     */
    public static function normalizeLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));

        if (\function_exists('transliterator_transliterate')) {
            $label = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $label) ?: $label;
        } elseif (\function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            $label = false !== $converted ? strtolower($converted) : $label;
        }

        $label = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $label) ?? $label;
        $label = preg_replace('/\s*[-–—]\s*\d+\s*%/', '', $label) ?? $label;
        $label = preg_replace('/[^a-z0-9\s]+/', ' ', $label) ?? $label;
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return trim($label);
    }

    public static function fromLabel(string $label, string $fallback = 'taxon'): string
    {
        $code = trim(preg_replace('/[^a-z0-9]+/', '_', self::normalizeLabel($label)) ?? '', '_');

        return '' !== $code ? $code : $fallback;
    }

    /**
     * @param array<string, true> $used codes already taken, updated
     */
    public static function unique(string $code, array &$used, string $separator = '_'): string
    {
        $candidate = $code;

        for ($suffix = 2; isset($used[$candidate]); ++$suffix) {
            $candidate = $code . $separator . $suffix;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    public static function slug(string $code): string
    {
        return str_replace('_', '-', $code);
    }
}
