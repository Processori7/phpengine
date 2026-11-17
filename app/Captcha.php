<?php
declare(strict_types=1);

/** Simple math challenge bound to the current PHP session. */
final class Captcha
{
    private const TTL = 300;

    /** @return array{question: string, token: string} */
    public static function issue(): array
    {
        $a = random_int(2, 20);
        $b = random_int(1, 15);
        if (random_int(0, 1) === 1) {
            if ($b > $a) [$a, $b] = [$b, $a];
            $op = '−';
            $answer = $a - $b;
        } else {
            $op = '+';
            $answer = $a + $b;
        }
        $_SESSION['captcha_hash'] = self::hash((string) $answer);
        $_SESSION['captcha_at'] = time();
        $question = $a . ' ' . $op . ' ' . $b . ' = ?';
        $_SESSION['captcha_question'] = $question;
        return ['question' => $question, 'token' => Security::randomToken(8)];
    }

    /** Reuse a fresh, unused challenge so duplicate page loads do not invalidate it. */
    public static function current(): ?array
    {
        if (empty($_SESSION['captcha_hash']) || empty($_SESSION['captcha_at'])) return null;
        if (time() - (int) $_SESSION['captcha_at'] > self::TTL) return null;
        $question = (string) ($_SESSION['captcha_question'] ?? '');
        if ($question === '') return null;
        return ['question' => $question, 'token' => Security::randomToken(8)];
    }

    public static function valid(?string $input): bool
    {
        if (empty($_SESSION['captcha_hash']) || time() - (int) ($_SESSION['captcha_at'] ?? 0) > self::TTL) {
            return false;
        }
        $value = str_replace(['−', '–', '—'], '-', trim((string) $input));
        $value = preg_replace('/\s+/', '', $value) ?? '';
        return hash_equals((string) $_SESSION['captcha_hash'], self::hash($value));
    }

    public static function clear(): void
    {
        unset($_SESSION['captcha_hash'], $_SESSION['captcha_at'], $_SESSION['captcha_question']);
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, session_id() ?: 'pyhost');
    }
}
