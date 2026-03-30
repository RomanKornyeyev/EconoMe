<?php

namespace App\Controller;

// Symfony
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\HttpFoundation\File\UploadedFile;

// Entities, repositories, forms
use App\Entity\User;
use App\Form\ProfileType;
use App\Form\PrivacyType;
use App\Form\ChangeEmailType;
use App\Form\ChangePasswordType;

// Services
use App\Service\UserService;
use App\Service\EmailChangeService;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\ProfilePhotoManager;

#[Route('/perfil')]
class ProfileController extends AbstractController
{
    private UserService $userService;
    private EmailChangeService $emailChangeService;

    public function __construct(UserService $userService, EmailChangeService $emailChangeService)
    {
        $this->userService = $userService;
        $this->emailChangeService = $emailChangeService;
    }

    #[Route('/', name: 'app_profile_index')]
    public function index(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Debes iniciar sesión para acceder a tu perfil.');
        }

        $name = $user->getName();
        $email = $user->getEmail();
        $nickname = $user->getNickname();
        $description = $user->getDescription();
        $profilePhoto = $user->getProfilePhoto();
        $emailVerified = $user->isVerified();
        $hasPendingEmail = $user->hasPendingEmailChange();
        $pendingEmail = $user->getPendingEmail();
        $createdAt = $user->getCreatedAt();
        $modifiedAt = $user->getModifiedAt();

        return $this->render('profile/index.html.twig', [
            'profile' => [
                'name' => $name,
                'email' => $email,
                'nickname' => $nickname,
                'description' => $description,
                'profilePhoto' => $profilePhoto,
                'emailVerified' => $emailVerified,
                'hasPendingEmail' => $hasPendingEmail,
                'pendingEmail' => $pendingEmail,
                'createdAt' => $createdAt,
                'modifiedAt' => $modifiedAt,
            ],
            'emailChangeStep' => $hasPendingEmail ? $this->emailChangeService->getEmailChangeStep($user) : 0,
        ]);
    }

    #[Route('/editar', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, ProfilePhotoManager $photoManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('Usuario autenticado no es App\\Entity\\User.');
        }
        $form = $this->createForm(ProfileType::class, $user, [
            'csrf_token_id' => 'account_edit_profile',
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $projectDir = $this->getParameter('kernel.project_dir');
            /** @var UploadedFile|null $file */
            $file = $form->get('profilePhotoFile')->getData();
            $remove = $form->has('removeProfilePhoto') && $form->get('removeProfilePhoto')->getData() === '1';
            $oldPath = $user->getProfilePhoto();

            if ($file instanceof UploadedFile) {
                $photoManager->deleteRelativePath($oldPath, $projectDir);
                $newPath = $photoManager->storeForUser($user->getId(), $file);
                $user->setProfilePhoto($newPath);
            } else {
                if ($remove) {
                    $photoManager->deleteRelativePath($oldPath, $projectDir);
                    $user->setProfilePhoto(null);
                }
            }

            $em->flush();

            $this->addFlash('success', 'Perfil actualizado correctamente.');
            return $this->redirectToRoute('app_profile_index');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/password', name: 'app_profile_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'limiter.change_password')]
        RateLimiterFactory $changePasswordLimiter,
        CacheInterface $cache
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Debes iniciar sesión para acceder a tu perfil.');
        }

        $form = $this->createForm(ChangePasswordType::class, null, [
            'csrf_token_id' => 'account_change_password',
        ]);

        $form->handleRequest($request);

        $ip = $request->getClientIp() ?? 'unknown';
        $limiterKey = 'user_'.$user->getId().'_ip_'.$ip;
        $lockKey = 'lock_change_password_'.$limiterKey;

        if ($request->isMethod('POST')) {
            $blockedUntilTs = $cache->get($lockKey, fn() => 0);

            if (is_int($blockedUntilTs) && $blockedUntilTs > time()) {
                $this->addFlash(
                    'danger_html',
                    'Demasiados intentos. Vuelve a intentarlo en unos minutos o <a href="'.$this->generateUrl('app_forgot_password').'">recupera tu contraseña</a>.'
                );
                return $this->redirectToRoute('app_profile_password');
            }
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            if ($form->get('currentPassword')->getErrors()->count() > 0) {
                $limiter = $changePasswordLimiter->create($limiterKey);
                $limit = $limiter->consume(1);

                if (!$limit->isAccepted()) {
                    $retryAfter = $limit->getRetryAfter();
                    $cache->delete($lockKey);
                    $cache->get(
                        $lockKey,
                        fn() => $retryAfter->getTimestamp()
                    );

                    $this->addFlash(
                        'danger_html',
                        'Demasiados intentos. Vuelve a intentarlo en unos minutos o <a href="'.$this->generateUrl('app_forgot_password').'">recupera tu contraseña</a>.'
                    );
                    return $this->redirectToRoute('app_profile_password');
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $plainNew = (string) $form->get('newPassword')->getData();

            if ($passwordHasher->isPasswordValid($user, $plainNew)) {
                $this->addFlash('danger', 'La nueva contraseña no puede ser igual a la actual.');
                return $this->redirectToRoute('app_profile_password');
            }

            $this->userService->changePassword($user, $plainNew);

            $this->addFlash('success', 'Contraseña actualizada correctamente.');
            return $this->redirectToRoute('app_profile_index');
        }

        return $this->render('profile/password.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/privacidad', name: 'app_profile_privacy', methods: ['GET', 'POST'])]
    public function privacy(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Debes iniciar sesión para acceder a tu perfil.');
        }

        $settings = $user->getSettings();

        $form = $this->createForm(PrivacyType::class, $settings, [
            'csrf_token_id' => 'account_privacy',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Ajustes de privacidad actualizados.');
            return $this->redirectToRoute('app_profile_index');
        }

        return $this->render('profile/privacy.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/email', name: 'app_profile_email', methods: ['GET', 'POST'])]
    public function email(Request $request): Response
    {
        $user = $this->getUser();

        $form = $this->createForm(ChangeEmailType::class, null, [
            'csrf_token_id' => 'account_change_email',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newEmail = (string) $form->get('newEmail')->getData();
                $this->emailChangeService->requestEmailChange($user, $newEmail);
                $this->addFlash('success', 'Te hemos enviado un enlace de autorización a tu email actual. Haz clic en él para continuar.');
                return $this->redirectToRoute('app_profile_email');
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
                return $this->redirectToRoute('app_profile_email');
            }
        }

        $emailChangeStep = $user instanceof User && $user->hasPendingEmailChange()
            ? $this->emailChangeService->getEmailChangeStep($user)
            : 0;

        return $this->render('profile/email.html.twig', [
            'form' => $form,
            'emailChangeStep' => $emailChangeStep,
        ]);
    }

    #[Route('/email/authorize/{token}', name: 'app_profile_email_authorize', methods: ['GET'])]
    public function authorizeEmail(string $token): RedirectResponse
    {
        try {
            $this->emailChangeService->authorizeEmailChange($token);
            $this->addFlash('success', 'Cambio autorizado. Te hemos enviado un enlace de confirmación a tu nuevo correo.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_profile_email');
    }

    #[Route('/email/confirm/{token}', name: 'app_profile_email_confirm', methods: ['GET'])]
    public function confirmEmail(string $token, Security $security, Request $request): RedirectResponse
    {
        try {
            $currentUser = $this->getUser();
            $changedUser = $this->emailChangeService->confirmEmailChange($token);

            $cookieName = 'REMEMBERME';
            $hadRememberMe = $request->cookies->has($cookieName);

            if ($currentUser instanceof User && $currentUser->getId() === $changedUser->getId()) {
                if ($hadRememberMe) {
                    $security->login($changedUser, 'form_login', 'main', [(new RememberMeBadge())->enable()]);
                } else {
                    $security->login($changedUser, 'form_login', 'main');
                }
            }

            $this->addFlash('success', 'Email actualizado correctamente.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_profile_index');
    }

    #[Route('/email/resend', name: 'app_profile_email_resend', methods: ['POST'])]
    public function resendEmail(Request $request, CsrfTokenManagerInterface $csrf): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Debes iniciar sesión para acceder a tu perfil.');
        }

        $submittedToken = (string) $request->request->get('_token');
        if (!$csrf->isTokenValid(new CsrfToken('account_email_resend', $submittedToken))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_profile_index');
        }

        try {
            $this->emailChangeService->resendEmailChange($user);
            $this->addFlash('success', 'Confirmación reenviada.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_profile_index');
    }

    #[Route('/email/cancel', name: 'app_profile_email_cancel', methods: ['POST'])]
    public function cancelEmail(Request $request, CsrfTokenManagerInterface $csrf): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Debes iniciar sesión para acceder a tu perfil.');
        }

        $submittedToken = (string) $request->request->get('_token');
        if (!$csrf->isTokenValid(new CsrfToken('account_email_cancel', $submittedToken))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_profile_index');
        }

        try {
            $this->emailChangeService->cancelEmailChange($user);
            $this->addFlash('success', 'Cambio de email cancelado.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_profile_index');
    }
}
