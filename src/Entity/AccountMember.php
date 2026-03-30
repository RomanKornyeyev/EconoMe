<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\Traits\TimestampableEntity;

#[ORM\Entity]
#[ORM\Table(name: "account_member")]
#[ORM\UniqueConstraint(name: "unique_active_member", columns: ["account_id", "user_id"])]
#[ORM\HasLifecycleCallbacks]
class AccountMember
{
    use TimestampableEntity;

    public const ROLE_OWNER = 'owner';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: "members")]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $user;

    #[ORM\Column(type: "string", length: 20)]
    private string $role = self::ROLE_VIEWER;

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    public function __construct(Account $account, User $user, string $role = self::ROLE_VIEWER)
    {
        $this->account = $account;
        $this->user = $user;
        $this->role = $role;
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function leave(): self
    {
        $this->leftAt = new \DateTimeImmutable();
        return $this;
    }

    public function rejoin(string $role = self::ROLE_VIEWER): self
    {
        $this->leftAt = null;
        $this->role = $role;
        return $this;
    }

    public function hasLeft(): bool
    {
        return $this->leftAt !== null;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isEditor(): bool
    {
        return $this->role === self::ROLE_EDITOR;
    }

    public function isViewer(): bool
    {
        return $this->role === self::ROLE_VIEWER;
    }

    public function canCreateTransactions(): bool
    {
        return !$this->hasLeft() && in_array($this->role, [self::ROLE_OWNER, self::ROLE_EDITOR]);
    }

    public function canManageAccount(): bool
    {
        return !$this->hasLeft() && $this->role === self::ROLE_OWNER;
    }
}
