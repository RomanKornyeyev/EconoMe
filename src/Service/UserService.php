<?php
namespace App\Service;

use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\UserToken;
use App\Service\CategoryTemplateSeeder;
use App\Service\MailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService
{
  private EntityManagerInterface $em;
  private MailService $mailService;
  private UserPasswordHasherInterface $passwordHasher;
  private CategoryTemplateSeeder $categoryTemplateSeeder;
  private RateLimiterFactory $resetPasswordEmailLimiter;

  public function __construct(
    EntityManagerInterface $em,
    UserPasswordHasherInterface $passwordHasher,
    MailService $mailService,
    CategoryTemplateSeeder $categoryTemplateSeeder,
    #[Autowire(service: 'limiter.reset_password_email')]
    RateLimiterFactory $resetPasswordEmailLimiter
  ) {
    $this->resetPasswordEmailLimiter = $resetPasswordEmailLimiter;
    $this->em = $em;
    $this->mailService = $mailService;
    $this->passwordHasher = $passwordHasher;
    $this->categoryTemplateSeeder = $categoryTemplateSeeder;
  }

  public function registerUser(User $user): void
  {
    // Hashear la contraseña
    $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPassword());
    $user->setPassword($hashedPassword);

    // Asignar rol por defecto
    $user->setRoles(["ROLE_USER"]);

    // Crear configuración de privacidad por defecto
    $settings = new UserSettings($user);
    $user->setSettings($settings);

    // Crear plantillas de categorías por defecto
    $this->categoryTemplateSeeder->seedForUser($user);

    // Generar un token único
    do {
      $token = new UserToken($user);
    } while ($this->em->getRepository(UserToken::class)->findOneBy(['token' => $token->getToken()]));

    // Persistir User y token en la BD
    $this->em->persist($user);
    $this->em->persist($token);
    $this->em->flush();

    // Enviar email de confirmación
    $this->mailService->sendConfirmationEmail($user->getEmail(), $token->getPlainToken(), $user->getName());
  }

  public function generateNewVerificationToken(User $user): void
  {
    // Eliminar tokens antiguos si existen
    $existingToken = $this->em->getRepository(UserToken::class)->findOneBy([
      'user' => $user,
      'type' => UserToken::TYPE_REGISTRATION,
      'used' => false
    ]);

    if ($existingToken) {
      $this->em->remove($existingToken);
    }

    // Crear nuevo token
    $newToken = new UserToken($user, UserToken::TYPE_REGISTRATION);

    // Guardar en la BD
    $this->em->persist($newToken);
    $this->em->flush();

    // Enviar email con el nuevo token
    $this->mailService->sendConfirmationEmail($user->getEmail(), $newToken->getPlainToken(), $user->getName());
  }

  /**
   * Manda un enlace de restablecimiento de contraseña. Comparte límite
   * (limiter.reset_password_email, por usuario) entre «Recuperar contraseña» y
   * el botón del perfil. Devuelve null si se envió, o cuándo se podrá pedir otro.
   */
  public function requestPasswordReset(User $user): ?\DateTimeImmutable
  {
    $limit = $this->resetPasswordEmailLimiter->create('user_'.$user->getId())->consume(1);

    if (!$limit->isAccepted()) {
      return $limit->getRetryAfter();
    }

    // Eliminar tokens antiguos
    $existingToken = $this->em->getRepository(UserToken::class)->findOneBy([
      'user' => $user,
      'type' => UserToken::TYPE_PASSWORD_RESET,
      'used' => false
    ]);

    if ($existingToken) {
      $this->em->remove($existingToken);
    }

    // Crear nuevo token
    $newToken = new UserToken($user, UserToken::TYPE_PASSWORD_RESET);

    // Guardar en la BD
    $this->em->persist($newToken);
    $this->em->flush();

    // Enviar email con el enlace de recuperación
    $this->mailService->sendResetPasswordEmail($user->getEmail(), $newToken->getPlainToken(), $user->getName());

    return null;
  }

  public function changePassword(User $user, string $plainNewPassword): void
  {
    // Hashear y guardar nueva contraseña
    $hashedPassword = $this->passwordHasher->hashPassword($user, $plainNewPassword);
    $user->setPassword($hashedPassword);

    // Invalidar tokens de reset pendientes (no usados)
    $tokens = $this->em->getRepository(UserToken::class)->findBy([
      'user' => $user,
      'type' => UserToken::TYPE_PASSWORD_RESET,
      'used' => false,
    ]);

    foreach ($tokens as $token) {
      $this->em->remove($token);
    }

    $this->em->flush();

    // Notificación por email (añade el método en MailService)
    $this->mailService->sendPasswordChangedEmail($user->getEmail(), $user->getName());
  }

}
