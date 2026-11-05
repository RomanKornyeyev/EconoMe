<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserToken;
use PHPUnit\Framework\TestCase;

/**
 * En BD solo se guarda el hash del token; el token en claro solo existe al
 * crearlo (para el email). El de restablecer contraseña caduca antes.
 */
class UserTokenTest extends TestCase
{
    public function testGuardaElHashYNoElTokenEnClaro(): void
    {
        $token = new UserToken(new User(), UserToken::TYPE_PASSWORD_RESET);

        $plain = $token->getPlainToken();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain);
        self::assertNotSame($plain, $token->getToken());
        self::assertSame(UserToken::hash($plain), $token->getToken());
    }

    public function testElTokenEnClaroNoSobreviveAlCargarDeBd(): void
    {
        // Doctrine hidrata sin constructor
        $loaded = (new \ReflectionClass(UserToken::class))->newInstanceWithoutConstructor();

        $this->expectException(\LogicException::class);
        $loaded->getPlainToken();
    }

    public function testResetDeContrasenaCaducaEnTresHoras(): void
    {
        $token = new UserToken(new User(), UserToken::TYPE_PASSWORD_RESET);

        self::assertEqualsWithDelta((new \DateTime('+3 hours'))->getTimestamp(), $token->getExpiresAt()->getTimestamp(), 5);
    }

    public function testElRestoDeTiposCaducaEn24Horas(): void
    {
        foreach ([UserToken::TYPE_REGISTRATION, UserToken::TYPE_EMAIL_CHANGE_AUTHORIZE, UserToken::TYPE_EMAIL_CHANGE_CONFIRM] as $type) {
            $token = new UserToken(new User(), $type);

            self::assertEqualsWithDelta((new \DateTime('+24 hours'))->getTimestamp(), $token->getExpiresAt()->getTimestamp(), 5, $type);
        }
    }
}
