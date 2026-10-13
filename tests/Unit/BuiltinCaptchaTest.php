<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Security\Captcha\BuiltinCaptcha;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BuiltinCaptchaTest extends TestCase
{
    private function captcha(): BuiltinCaptcha
    {
        return new BuiltinCaptcha('unit-secret-0123456789abcdef', new ArrayAdapter());
    }

    private function answerFor(BuiltinCaptcha $c, string $token): string
    {
        $m = new \ReflectionMethod($c, 'answer');

        return $m->invoke($c, explode('.', $token)[0]);
    }

    public function testCorrectAnswerIsAcceptedOnceOnly(): void
    {
        $c = $this->captcha();
        $issue = $c->issue();
        $answer = $this->answerFor($c, $issue['token']);
        self::assertTrue($c->verify($issue['token'], strtolower(' ' . $answer)));
        self::assertFalse($c->verify($issue['token'], $answer), 'a token must not be reusable');
    }

    public function testWrongTamperedAndExpiredTokensAreRejected(): void
    {
        $c = $this->captcha();
        $issue = $c->issue();
        self::assertFalse($c->verify($issue['token'], 'WRONG'));
        self::assertFalse($c->verify($issue['token'], ''));
        [$nonce, $exp, $sig] = explode('.', $issue['token']);
        self::assertFalse($c->verify($nonce . '.' . ((int) $exp + 500) . '.' . $sig, $this->answerFor($c, $issue['token'])), 'expiry is signed');
        self::assertFalse($c->verify('garbage', 'ABC'));
        $expired = $nonce . '.' . (time() - 5) . '.' . substr(hash_hmac('sha256', 'cap|' . $nonce . '|' . (time() - 5), 'unit-secret-0123456789abcdef'), 0, 32);
        self::assertFalse($c->verify($expired, $this->answerFor($c, $issue['token'])));
    }

    public function testAnotherSecretCannotForgeTokens(): void
    {
        $issue = $this->captcha()->issue();
        $other = new BuiltinCaptcha('different-secret-000000000000', new ArrayAdapter());
        self::assertFalse($other->verify($issue['token'], 'ABCDE'));
        self::assertNull($other->image($issue['token']));
    }

    public function testImageIsPngForValidTokenOnly(): void
    {
        $c = $this->captcha();
        if (!$c->imageMode()) {
            self::markTestSkipped('GD is not available.');
        }
        $png = $c->image($c->issue()['token']);
        self::assertNotNull($png);
        self::assertSame("\x89PNG", substr((string) $png, 0, 4));
        self::assertNull($c->image('nope'));
    }

    private function slide(BuiltinCaptcha $c, string $token, int $ms, int $moves, int $jitter, string $mode = 'p'): string
    {
        $key = $c->slideKey(explode('.', $token)[0]);

        return implode('.', ['v1', $ms, $moves, $jitter, $mode, BuiltinCaptcha::slideHash($key . '|' . $ms . '|' . $moves . '|' . $jitter . '|' . $mode)]);
    }

    public function testSliderAcceptsAHumanDragAndRejectsScriptedOnes(): void
    {
        $c = $this->captcha();
        // the token must have existed for a moment before a drag can be this long
        $issue = $c->issue();
        [$nonce, $exp] = explode('.', $issue['token']);
        $old = $nonce . '.' . ((int) $exp - 5) . '.' . substr(hash_hmac('sha256', 'cap|' . $nonce . '|' . ((int) $exp - 5), 'unit-secret-0123456789abcdef'), 0, 32);
        self::assertTrue($c->verify($old, $this->slide($c, $old, 900, 14, 5)));
        self::assertFalse($c->verify($old, $this->slide($c, $old, 900, 14, 5)), 'a token is accepted once');

        foreach ([[900, 1, 0], [900, 14, 0], [50, 14, 5], [900, 14, 5]] as $i => [$ms, $moves, $jitter]) {
            $issue = $c->issue();
            [$n, $e] = explode('.', $issue['token']);
            $t = $n . '.' . ((int) $e - 5) . '.' . substr(hash_hmac('sha256', 'cap|' . $n . '|' . ((int) $e - 5), 'unit-secret-0123456789abcdef'), 0, 32);
            $answer = $this->slide($c, $t, $ms, $moves, $jitter);
            if ($i === 3) {
                $answer = substr($answer, 0, -1) . (substr($answer, -1) === '0' ? '1' : '0'); // wrong seal
            }
            self::assertFalse($c->verify($t, $answer), 'case ' . $i);
        }
        // a drag longer than the token has existed cannot be real
        $fresh = $c->issue();
        self::assertFalse($c->verify($fresh['token'], $this->slide($c, $fresh['token'], 60000, 30, 8)));
    }
}
