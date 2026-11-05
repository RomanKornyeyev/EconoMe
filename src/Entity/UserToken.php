<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Traits\TimestampableEntity;

#[ORM\Entity]
#[ORM\Table(name: "user_token")]
#[ORM\HasLifecycleCallbacks]
class UserToken
{
    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_PASSWORD_RESET = 'password_reset';
    public const TYPE_EMAIL_CHANGE = 'email_change'; // legacy
    public const TYPE_EMAIL_CHANGE_AUTHORIZE = 'email_change_authorize';
    public const TYPE_EMAIL_CHANGE_CONFIRM = 'email_change_confirm';

    // Caducidad por tipo; los que no aparecen usan DEFAULT_LIFETIME
    public const LIFETIMES = [
        self::TYPE_PASSWORD_RESET => '+3 hours',
    ];
    public const DEFAULT_LIFETIME = '+24 hours';

    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $user;

    // En BD solo se guarda el hash (sha256) del token que viaja en el enlace
    #[ORM\Column(type: "string", unique: true)]
    #[Assert\NotBlank]
    private string $token;

    // Token en claro: solo vive en memoria al crearlo, para mandarlo por email
    private ?string $plainToken = null;

    #[ORM\Column(type: "string")]
    #[Assert\Choice(choices: [self::TYPE_REGISTRATION, self::TYPE_PASSWORD_RESET, self::TYPE_EMAIL_CHANGE, self::TYPE_EMAIL_CHANGE_AUTHORIZE, self::TYPE_EMAIL_CHANGE_CONFIRM])]
    private string $type;

    #[ORM\Column(type: "datetime")]
    #[Assert\NotBlank]
    private \DateTime $expiresAt;

    #[ORM\Column(type: "boolean")]
    private bool $used = false;

    public function __construct(User $user, string $type = self::TYPE_REGISTRATION)
    {
        $this->user = $user;
        $this->type = $type;
        $this->plainToken = bin2hex(random_bytes(32));
        $this->token = self::hash($this->plainToken);
        $this->expiresAt = (new \DateTime())->modify(self::LIFETIMES[$type] ?? self::DEFAULT_LIFETIME);
    }

    /** Hash con el que se busca en BD el token que llega en un enlace. */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isExpired(): bool
    {
        return new \DateTime() > $this->expiresAt;
    }

    public function markAsUsed(): void
    {
        $this->used = true;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /** Hash guardado en BD. Para el enlace del email, getPlainToken(). */
    public function getToken(): string
    {
        return $this->token;
    }

    public function getPlainToken(): string
    {
        if ($this->plainToken === null) {
            throw new \LogicException('El token en claro solo está disponible al crearlo.');
        }

        return $this->plainToken;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getExpiresAt(): \DateTime
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTime $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    public function setUsed(bool $used): self
    {
        $this->used = $used;
        return $this;
    }
}
