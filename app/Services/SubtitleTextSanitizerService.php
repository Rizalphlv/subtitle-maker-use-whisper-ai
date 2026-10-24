<?php

namespace App\Services;

class SubtitleTextSanitizerService
{
    public function sanitize(string $text): string
    {
        $text = trim($text);

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

        return $text !== '' ? $text : '[inaudible]';
    }

    private function isLongRepeatedVocalization(string $text): bool
    {
        $plain = mb_strtolower((string) preg_replace('/[^\p{L}\s]+/u', ' ', $text));
        $tokens = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) === 1 && mb_strlen($tokens[0]) >= 12) {
            return $this->normalizeVocalization($tokens[0]) === 'ah';
        }

        if (count($tokens) < 8) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! in_array($this->normalizeVocalization($token), ['a', 'ah', 'ha', 'oh', 'uh', 'm', 'hm'], true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeVocalization(string $token): string
    {
        return (string) preg_replace('/(.)\1+/u', '$1', $token);
    }
}
