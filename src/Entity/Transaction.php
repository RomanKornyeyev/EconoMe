<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: "App\Repository\TransactionRepository")]
#[ORM\Table(name: "transaction")]
#[ORM\Index(name: "idx_transaction_date", columns: ["date"])]
#[ORM\Index(name: "idx_transaction_account_date", columns: ["account_id", "date"])]
#[ORM\HasLifecycleCallbacks]
class Transaction
{
    use TimestampableEntity;

    public const TYPE_EXPENSE = 'expense';
    public const TYPE_INCOME = 'income';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: "transactions")]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: "SET NULL")]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: RecurringTransaction::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: "SET NULL")]
    private ?RecurringTransaction $recurringSource = null;

    #[ORM\Column(type: "string", length: 10)]
    #[Assert\Choice(choices: [self::TYPE_EXPENSE, self::TYPE_INCOME])]
    private string $type = self::TYPE_EXPENSE;

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(type: "date")]
    #[Assert\NotNull]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: "string", length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $description = null;

    public function __construct(Account $account, User $createdBy)
    {
        $this->account = $account;
        $this->createdBy = $createdBy;
        $this->date = new \DateTime();
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

    public function getRecurringSource(): ?RecurringTransaction
    {
        return $this->recurringSource;
    }

    public function setRecurringSource(?RecurringTransaction $recurringSource): self
    {
        $this->recurringSource = $recurringSource;
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

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = $date;
        return $this;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function isExpense(): bool
    {
        return $this->type === self::TYPE_EXPENSE;
    }

    public function isIncome(): bool
    {
        return $this->type === self::TYPE_INCOME;
    }

    public function wasGeneratedAutomatically(): bool
    {
        return $this->recurringSource !== null;
    }
}
