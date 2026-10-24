<?php

namespace App\Services;

class SubtitleTextSanitizerService
{
    public function sanitize(string $text, ?float $durationSeconds = null): string
    {
        $text = trim($text);

        if (str_contains($text, "\n")) {
            $sanitizedLines = array_map(
                fn (string $line): string => $this->sanitize($line, $durationSeconds),
                preg_split('/\R/u', $text) ?: []
            );

            // Avoid showing the same generated sound label on adjacent lines.
            $sanitizedLines = array_values(array_filter(
                $sanitizedLines,
                fn (string $line, int $index): bool => $index === 0
                    || ! in_array($line, ['[vocalization]', '[breathing]'], true)
                    || $line !== $sanitizedLines[$index - 1],
                ARRAY_FILTER_USE_BOTH
            ));

            return implode("\n", $sanitizedLines);
        }

        // Drop invalid replacement markers without trying to rewrite valid Unicode.
        $text = str_replace(["\u{FFFD}", 'ï¿½'], '', $text);

        // Remove only known model/editor annotations, not ordinary parenthetical dialogue.
        $text = (string) preg_replace(
            '/\s*\((?:continued|note\s*:.*?|this was .*?not literal speech\.?|editor(?:\'s)? note\s*:.*?)\)\s*/iu',
            ' ',
            $text
        );
        $text = (string) preg_replace(
            '/\s+Show more of the scene and consider pacing the dialogue more naturally.*$/iu',
            '',
            $text
        );
        $text = trim((string) preg_replace('/[ \t]{2,}/u', ' ', $text));

        if ($this->isLongRepeatedVocalization($text)) {
            return '[vocalization]';
        }

        if ($this->isLongRepeatedBreathing($text)) {
            return '[breathing]';
        }

        if ($this->isLikelyChunkHallucination($text, $durationSeconds)) {
            return '[inaudible]';
        }

        return $text !== '' ? $text : '[inaudible]';
    }

    private function isLongRepeatedVocalization(string $text): bool
    {
        $plain = mb_strtolower((string) preg_replace('/[^\p{L}\s]+/u', ' ', $text));
        $tokens = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) === 1 && mb_strlen($tokens[0]) >= 12) {
            return in_array(
                $this->normalizeVocalization($tokens[0]),
                ['a', 'ah', 'e', 'ha', 'oh', 'u', 'uh', 'm', 'hm', 'ㅋ'],
                true
            );
        }

        if (count($tokens) < 8) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! in_array($this->normalizeVocalization($token), ['a', 'ah', 'e', 'ha', 'oh', 'u', 'uh', 'm', 'hm', 'ㅋ'], true)) {
                return false;
            }
        }

        return true;
    }

    private function isLongRepeatedBreathing(string $text): bool
    {
        $plain = mb_strtolower((string) preg_replace('/[^\p{L}\s]+/u', ' ', $text));
        $tokens = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) < 8) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! in_array($token, ['inhale', 'inhales', 'inhaling', 'exhale', 'exhales', 'exhaling'], true)) {
                return false;
            }
        }

        return true;
    }

    private function isLikelyChunkHallucination(string $text, ?float $durationSeconds): bool
    {
        if (preg_match('/^by\s+[\p{L}\p{N}._-]{1,20}$/iu', $text) === 1) {
            return true;
        }

        if ($durationSeconds === null || $durationSeconds < 20) {
            return false;
        }

        return preg_match('/^thank you for watching[.!]?$/iu', $text) === 1;
    }

    private function normalizeVocalization(string $token): string
    {
        return (string) preg_replace('/(.)\1+/u', '$1', $token);
    }
}
