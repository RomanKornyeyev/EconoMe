<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * La firma de la cookie «Recuérdame» incluye el hash de la contraseña, así que
 * al cambiarla la cookie del propio dispositivo también deja de valer: la sesión
 * sigue, pero al caducar habría que volver a entrar. Si el usuario venía con
 * cookie, se la volvemos a emitir firmada con la contraseña nueva.
 */
final class RememberMeRenewer
{
    // Nombre por defecto de remember_me en security.yaml
    private const COOKIE_NAME = 'REMEMBERME';

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
    ) {}

    public function renewIfPresent(User $user): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request?->cookies->has(self::COOKIE_NAME)) {
            return;
        }

        $this->security->login($user, 'form_login', 'main', [(new RememberMeBadge())->enable()]);
    }
}
