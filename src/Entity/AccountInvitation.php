<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableEntity;
use App\Repository\AccountInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountInvitationRepository::class)]
#[ORM\Table(name: "account_invitation")]
#[ORM\UniqueConstraint(name: "unique_invitation", columns: ["account_id", "invitee_id"])]
#[ORM\HasLifecycleCallbacks]
class AccountInvitation
{
    use TimestampableEntity;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $inviter;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $invitee;

    #[ORM\Column(type: "string", length: 20)]
    private string $role = AccountMember::ROLE_EDITOR;

    #[ORM\Column(type: "string", length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    public function __construct(Account $account, User $inviter, User $invitee, string $role = AccountMember::ROLE_EDITOR)
    {
        $this->account = $account;
        $this->inviter = $inviter;
        $this->invitee = $invitee;
        $this->role = $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getInviter(): User
    {
        return $this->inviter;
    }

    public function getInvitee(): User
    {
        return $this->invitee;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    /**
     * Reactiva una invitación previa (rechazada/cancelada) como pendiente,
     * actualizando quién invita y con qué rol.
     */
    public function renew(User $inviter, string $role): self
    {
        $this->inviter = $inviter;
        $this->role = $role;
        $this->status = self::STATUS_PENDING;
        $this->respondedAt = null;
        return $this;
    }

    public function accept(): self
    {
        $this->status = self::STATUS_ACCEPTED;
        $this->respondedAt = new \DateTimeImmutable();
        return $this;
    }

    public function reject(): self
    {
        $this->status = self::STATUS_REJECTED;
        $this->respondedAt = new \DateTimeImmutable();
        return $this;
    }

    public function cancel(): self
    {
        $this->status = self::STATUS_CANCELLED;
        $this->respondedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
