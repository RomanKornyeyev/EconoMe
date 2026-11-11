<?php

namespace App\Entity;

use App\Budget\AmountSchedule;
use App\Budget\BudgetPeriod;
use App\Entity\Traits\TimestampableEntity;
use App\Enum\Frequency;
use App\Repository\BudgetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Límite de gasto o meta de ingreso de una cuenta, evaluado por periodos de
 * calendario. Diseño completo en claude/presupuestos.md.
 *
 * - Ámbito: una categoría o, con `category` a null, toda la cuenta.
 * - Vigencia: del periodo de `startDate` al de `endDate` (null = sin fin). Las
 *   dos fechas están alineadas a periodos: primer día del inicial y último del
 *   final.
 * - Importe: no es un campo, son los tramos de `amounts` (ver AmountSchedule).
 * - Tipo, ámbito y frecuencia no cambian tras crearlo: cambiarían el significado
 *   del historial. Para eso se finaliza y se crea otro.
 * - Sin nombre propio: el de su categoría, o «Gasto total» / «Ingresos totales».
 */
#[ORM\Entity(repositoryClass: BudgetRepository::class)]
#[ORM\Table(name: 'budget')]
#[ORM\HasLifecycleCallbacks]
class Budget
{
    use TimestampableEntity;

    public const TYPE_EXPENSE = 'expense';
    public const TYPE_INCOME = 'income';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    /**
     * CASCADE y no SET NULL como en Transaction: con SET NULL, borrar la
     * categoría convertiría su presupuesto en uno de toda la cuenta sin avisar.
     */
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Category $category;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(type: 'string', length: 10)]
    private string $type;

    #[ORM\Column(type: 'string', length: 10, enumType: Frequency::class)]
    private Frequency $frequency;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    /** @var Collection<int, BudgetAmount> */
    #[ORM\OneToMany(targetEntity: BudgetAmount::class, mappedBy: 'budget', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['effectiveFrom' => 'ASC'])]
    private Collection $amounts;

    public function __construct(
        Account $account,
        User $createdBy,
        string $type,
        Frequency $frequency,
        ?Category $category,
        AmountSchedule $schedule,
    ) {
        if (!\in_array($type, [self::TYPE_EXPENSE, self::TYPE_INCOME], true)) {
            throw new \InvalidArgumentException(sprintf('Tipo de presupuesto no válido: %s', $type));
        }
        if ($schedule->frequency() !== $frequency) {
            throw new \InvalidArgumentException('Los tramos no son de la frecuencia del presupuesto.');
        }

        $this->account = $account;
        $this->createdBy = $createdBy;
        $this->type = $type;
        $this->frequency = $frequency;
        $this->category = $category;
        $this->amounts = new ArrayCollection();
        $this->applySchedule($schedule);
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

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isExpense(): bool
    {
        return $this->type === self::TYPE_EXPENSE;
    }

    public function getFrequency(): Frequency
    {
        return $this->frequency;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function isAccountWide(): bool
    {
        return $this->category === null;
    }

    /** «Alimentación», o «Gasto total» / «Ingresos totales» si es de toda la cuenta. */
    public function getName(): string
    {
        return $this->category?->getName() ?? ($this->isExpense() ? 'Gasto total' : 'Ingresos totales');
    }

    public function getColor(): ?string
    {
        return $this->category?->getColor();
    }

    /** «Límite mensual», «Meta semanal». */
    public function getKindLabel(): string
    {
        return ($this->isExpense() ? 'Límite ' : 'Meta ') . $this->frequency->adjective();
    }

    public function firstPeriod(): BudgetPeriod
    {
        return BudgetPeriod::containing($this->frequency, $this->startDate);
    }

    public function lastPeriod(): ?BudgetPeriod
    {
        return $this->endDate === null ? null : BudgetPeriod::containing($this->frequency, $this->endDate);
    }

    /** El periodo de este presupuesto que contiene la fecha. */
    public function periodAt(\DateTimeInterface $date): BudgetPeriod
    {
        return BudgetPeriod::containing($this->frequency, $date);
    }

    /** Ya no le quedan periodos por delante: terminó antes de hoy. */
    public function hasEnded(\DateTimeInterface $today): bool
    {
        return $this->endDate !== null && $this->endDate < \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
    }

    /** Aún no ha empezado. */
    public function isUpcoming(\DateTimeInterface $today): bool
    {
        return $this->startDate > \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
    }

    // ── Tramos de importe ─────────────────────────────────────────────────

    /** @return Collection<int, BudgetAmount> */
    public function getAmounts(): Collection
    {
        return $this->amounts;
    }

    public function getSchedule(): AmountSchedule
    {
        $steps = [];
        foreach ($this->amounts as $amount) {
            $steps[$amount->getEffectiveFrom()->format('Y-m-d')] = $amount->getAmount();
        }

        return new AmountSchedule($this->firstPeriod(), $this->lastPeriod(), $steps);
    }

    /**
     * Sincroniza vigencia y tramos con el horario dado.
     *
     * Reutiliza los tramos cuya fecha se mantiene (solo cambia el importe) en vez
     * de borrar y crear: Doctrine ejecuta las inserciones antes que los borrados,
     * y con el índice único (budget, effective_from) chocarían.
     */
    public function applySchedule(AmountSchedule $schedule): void
    {
        $this->startDate = $schedule->first->start;
        $this->endDate = $schedule->last?->end;

        $steps = $schedule->steps();
        $existing = [];
        foreach ($this->amounts as $amount) {
            $key = $amount->getEffectiveFrom()->format('Y-m-d');
            if (!isset($steps[$key])) {
                $this->amounts->removeElement($amount);
                continue;
            }
            $existing[$key] = $amount;
        }

        foreach ($steps as $key => $value) {
            if (isset($existing[$key])) {
                if (bccomp($existing[$key]->getAmount(), $value, 2) !== 0) {
                    $existing[$key]->setAmount($value);
                }
                continue;
            }
            $this->amounts->add(new BudgetAmount($this, new \DateTimeImmutable($key), $value));
        }
    }
}
