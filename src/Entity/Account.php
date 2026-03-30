<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Traits\TimestampableEntity;

#[ORM\Entity]
#[ORM\Table(name: "account")]
#[ORM\HasLifecycleCallbacks]
class Account
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    private ?string $name = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: "string", length: 3, options: ["default" => "EUR"])]
    private string $currency = 'EUR';

    #[ORM\Column(type: "string", length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(type: "string", length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\OneToMany(targetEntity: AccountMember::class, mappedBy: "account", cascade: ["persist", "remove"])]
    private Collection $members;

    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: "account")]
    private Collection $transactions;

    #[ORM\OneToMany(targetEntity: RecurringTransaction::class, mappedBy: "account")]
    private Collection $recurringTransactions;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->transactions = new ArrayCollection();
        $this->recurringTransactions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * Devuelve solo los miembros activos (sin leftAt).
     */
    public function getActiveMembers(): Collection
    {
        return $this->members->filter(fn(AccountMember $m) => !$m->hasLeft());
    }

    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function getRecurringTransactions(): Collection
    {
        return $this->recurringTransactions;
    }
}
