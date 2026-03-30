<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: "App\Repository\RecurringTransactionRepository")]
#[ORM\Table(name: "recurring_transaction")]
#[ORM\HasLifecycleCallbacks]
class RecurringTransaction
{
    use TimestampableEntity;

    public const TYPE_EXPENSE = 'expense';
    public const TYPE_INCOME = 'income';

    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_YEARLY = 'yearly';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: "recurringTransactions")]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: "SET NULL")]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $createdBy;

    #[ORM\Column(type: "string", length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(type: "string", length: 10)]
    #[Assert\Choice([self::TYPE_EXPENSE, self::TYPE_INCOME])]
    private string $type = self::TYPE_EXPENSE;

    #[ORM\Column(type: "string", length: 10)]
    #[Assert\Choice([self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_MONTHLY, self::FREQ_YEARLY])]
    private string $frequency = self::FREQ_MONTHLY;

    #[ORM\Column(type: "integer")]
    #[Assert\Range(min: 1, max: 31)]
    private int $dayOfExecution = 1;

    #[ORM\Column(type: "date")]
    #[Assert\NotNull]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: "date", nullable: true)]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private bool $isActive = true;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $lastGeneratedAt = null;

    public function __construct(Account $account, User $createdBy)
    {
        $this->account = $account;
        $this->createdBy = $createdBy;
        $this->startDate = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
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

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function setFrequency(string $frequency): self
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function getDayOfExecution(): int
    {
        return $this->dayOfExecution;
    }

    public function setDayOfExecution(int $dayOfExecution): self
    {
        $this->dayOfExecution = $dayOfExecution;
        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeInterface $startDate): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeInterface $endDate): self
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getLastGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->lastGeneratedAt;
    }

    public function setLastGeneratedAt(\DateTimeImmutable $lastGeneratedAt): self
    {
        $this->lastGeneratedAt = $lastGeneratedAt;
        return $this;
    }

    public function isIndefinite(): bool
    {
        return $this->endDate === null;
    }

    /**
     * Comprueba si la suscripción ha expirado.
     */
    public function hasExpired(): bool
    {
        if ($this->isIndefinite()) {
            return false;
        }
        return new \DateTime() > $this->endDate;
    }

    /**
     * Comprueba si debe generar hoy.
     */
    public function shouldGenerateToday(): bool
    {
        if (!$this->isActive || $this->hasExpired()) {
            return false;
        }

        $today = new \DateTime();

        if ($today < $this->startDate) {
            return false;
        }

        return match ($this->frequency) {
            self::FREQ_DAILY => true,
            self::FREQ_WEEKLY => (int)$today->format('N') === $this->dayOfExecution,
            self::FREQ_MONTHLY => (int)$today->format('j') === $this->dayOfExecution,
            self::FREQ_YEARLY => (int)$today->format('z') + 1 === $this->dayOfExecution,
            default => false,
        };
    }
}
