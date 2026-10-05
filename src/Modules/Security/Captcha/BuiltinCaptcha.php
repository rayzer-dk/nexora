<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Captcha;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Self-hosted captcha with no third-party requests. Stateless: a signed, expiring token carries a nonce from which the
 * expected answer is derived (HMAC), so it works with page caches and without sessions. A token is accepted once.
 * Uses a distorted image when GD is available, otherwise a short arithmetic question.
 */
final class BuiltinCaptcha
{
    private const ALPHABET = 'ACDEFHJKLMNPRTUVWXY34679';
    private const TTL = 900;

    public function __construct(private readonly string $secret, private readonly CacheItemPoolInterface $cache)
    {
    }

    public function imageMode(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /** @return array{token:string,mode:string,question:string} */
    public function issue(): array
    {
        $nonce = bin2hex(random_bytes(8));
        $exp = time() + self::TTL;
        $token = $nonce . '.' . $exp . '.' . $this->sign($nonce, $exp);

        return ['token' => $token, 'mode' => $this->imageMode() ? 'image' : 'math', 'question' => $this->imageMode() ? '' : $this->question($nonce)];
    }

    public function verify(string $token, string $answer): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || preg_match('/^[a-f0-9]{16}$/', $parts[0]) !== 1 || !ctype_digit($parts[1])) {
            return false;
        }
        [$nonce, $exp, $sig] = $parts;
        if (!hash_equals($this->sign($nonce, (int) $exp), $sig) || (int) $exp < time()) {
            return false;
        }
        $expected = $this->answer($nonce);
        $given = strtoupper(preg_replace('/\s+/', '', $answer) ?? '');
        if ($given === '' || !hash_equals($expected, $given)) {
            return false;
        }
        try {
            $item = $this->cache->getItem('captcha_used_' . $nonce);
            if ($item->isHit()) {
                return false;
            }
            $item->set(1)->expiresAfter(self::TTL + 60);
            $this->cache->save($item);
        } catch (\Throwable) {
            // cache unavailable: the signature and expiry still bound replay to the token lifetime
        }

        return true;
    }

    /** PNG bytes for a token, or null when the token is invalid/expired or GD is missing. */
    public function image(string $token): ?string
    {
        $parts = explode('.', $token);
        if (!$this->imageMode() || count($parts) !== 3 || preg_match('/^[a-f0-9]{16}$/', $parts[0]) !== 1 || !ctype_digit($parts[1])) {
            return null;
        }
        if (!hash_equals($this->sign($parts[0], (int) $parts[1]), $parts[2]) || (int) $parts[1] < time()) {
            return null;
        }
        $text = $this->answer($parts[0]);
        $w = 168;
        $h = 56;
        $canvas = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($canvas, 244, 246, 250);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $bg);
        for ($i = 0; $i < 90; $i++) {
            imagesetpixel($canvas, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($canvas, random_int(150, 210), random_int(150, 210), random_int(150, 210)));
        }
        $len = strlen($text);
        $cell = intdiv($w - 16, $len);
        for ($i = 0; $i < $len; $i++) {
            $glyph = imagecreatetruecolor(16, 22);
            $gbg = imagecolorallocate($glyph, 244, 246, 250);
            imagefilledrectangle($glyph, 0, 0, 16, 22, $gbg);
            imagechar($glyph, 5, 3, 3, $text[$i], imagecolorallocate($glyph, random_int(10, 90), random_int(10, 90), random_int(60, 140)));
            $size = random_int(30, 38);
            $tmp = imagecreatetruecolor($size, $size + 8);
            imagecopyresized($tmp, $glyph, 0, 0, 0, 0, $size, $size + 8, 16, 22);
            $rot = imagerotate($tmp, random_int(-38, 38), imagecolorallocate($tmp, 244, 246, 250)) ?: $tmp;
            imagecopymerge($canvas, $rot, 8 + $i * $cell + random_int(0, 6), random_int(2, 8), 0, 0, imagesx($rot), imagesy($rot), 100);
        }
        for ($i = 0; $i < 4; $i++) {
            imageline($canvas, random_int(0, 30), random_int(0, $h), random_int($w - 30, $w), random_int(0, $h), imagecolorallocate($canvas, random_int(80, 160), random_int(80, 160), random_int(80, 160)));
        }
        $canvas = $this->warp($canvas, $w, $h);
        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /** Sine-wave distortion of columns and rows: easy for a person to read, hard for plain OCR. */
    private function warp(\GdImage $src, int $w, int $h): \GdImage
    {
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 244, 246, 250));
        $phaseX = random_int(0, 628) / 100;
        $phaseY = random_int(0, 628) / 100;
        $ampX = random_int(2, 3);
        $ampY = random_int(2, 4);
        for ($x = 0; $x < $w; ++$x) {
            $dy = (int) round(sin($x / 11 + $phaseX) * $ampY);
            for ($y = 0; $y < $h; ++$y) {
                $sx = $x + (int) round(sin($y / 9 + $phaseY) * $ampX);
                $sy = $y + $dy;
                if ($sx >= 0 && $sx < $w && $sy >= 0 && $sy < $h) {
                    imagesetpixel($out, $x, $y, imagecolorat($src, $sx, $sy));
                }
            }
        }

        return $out;
    }

    private function sign(string $nonce, int $exp): string
    {
        return substr(hash_hmac('sha256', 'cap|' . $nonce . '|' . $exp, $this->secret), 0, 32);
    }

    private function answer(string $nonce): string
    {
        $raw = hash_hmac('sha256', 'code|' . $nonce, $this->secret, true);
        if (!$this->imageMode()) {
            return (string) ((ord($raw[0]) % 9 + 1) + (ord($raw[1]) % 9 + 1));
        }
        $out = '';
        $n = strlen(self::ALPHABET);
        for ($i = 0; $i < 5; $i++) {
            $out .= self::ALPHABET[ord($raw[$i]) % $n];
        }

        return $out;
    }

    private function question(string $nonce): string
    {
        $raw = hash_hmac('sha256', 'code|' . $nonce, $this->secret, true);

        return (ord($raw[0]) % 9 + 1) . ' + ' . (ord($raw[1]) % 9 + 1) . ' = ?';
    }
}
