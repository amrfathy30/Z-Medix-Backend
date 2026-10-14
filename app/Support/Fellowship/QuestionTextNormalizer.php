<?php

namespace App\Support\Fellowship;

use Illuminate\Support\Str;

/**
 * Reduces a question's wording to the form duplicate detection compares.
 *
 * Two uploads of the same question rarely match byte for byte: one has a
 * trailing space, one capitalises differently, one was pasted with a non-
 * breaking space or smart quotes out of a word processor. Normalizing away
 * exactly those differences — and nothing that changes meaning — is what lets
 * the hash be an index lookup rather than a fuzzy scan.
 *
 * Deliberately conservative: punctuation and digits are kept, because "give 2 mg"
 * and "give 20 mg" are not the same question.
 */
final class QuestionTextNormalizer
{
    /**
     * Characters that carry no meaning here but differ between sources: the
     * typographic quotes and dashes word processors substitute, and the spaces
     * that survive a copy-paste out of a PDF.
     *
     * @var array<string, string>
     */
    private const SUBSTITUTIONS = [
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201A}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{201E}" => '"',
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{2212}" => '-',
        "\u{00A0}" => ' ',
        "\u{200B}" => '',
        "\u{FEFF}" => '',
    ];

    public static function normalize(?string $text): string
    {
        $text = (string) $text;
        $text = strtr($text, self::SUBSTITUTIONS);
        $text = Str::lower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * The stored `content_hash`. Fixed width and index-friendly, and stable
     * across PHP versions, so a hash written today still matches one written by
     * a later import.
     */
    public static function hash(?string $text): string
    {
        return hash('sha256', self::normalize($text));
    }
}
