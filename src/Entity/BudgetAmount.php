<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un tramo de importe de un presupuesto: el importe que rige desde un periodo.
 *
 * No se edita suelto: Budget::applySchedule() sincroniza los tramos con un
 * AmountSchedule, que es quien garantiza sus invariantes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'budget_amount')]
#[ORM\UniqueConstraint(name: 'uniq_budget_amount_from', columns: ['budget_id', 'effective_from'])]
class BudgetAmount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Budget::class, inversedBy: 'amounts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Budget $budget;

    /** Primer día de un periodo de la frecuencia del presupuesto. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    public function __construct(Budget $budget, \DateTimeImmutable $effectiveFrom, string $amount)
    {
        $this->budget = $budget;
        $this->effectiveFrom = $effectiveFrom;
        $this->amount = $amount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudget(): Budget
    {
        return $this->budget;
    }

    public function getEffectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }
}
