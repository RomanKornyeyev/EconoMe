<?php

namespace App\Budget;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\Frequency;
use App\Form\BudgetAmountType;
use App\Repository\BudgetRepository;

/**
 * Casos de uso de los presupuestos que no son pura lógica de tramos: crear,
 * comprobar la unicidad, cambiar el importe con su resumen previo y finalizar.
 *
 * El resumen («Qué va a pasar») se calcula aquí, en el servidor, con el mismo
 * AmountSchedule que luego guarda: así la lógica de tramos no se duplica en JS.
 */
class BudgetEditor
{
    public function __construct(
        private BudgetRepository $budgets,
        private BudgetEvaluator $evaluator,
    ) {}

    /** Un presupuesto nuevo, sin persistir. */
    public function create(
        Account $account,
        User $user,
        string $type,
        Frequency $frequency,
        ?Category $category,
        BudgetPeriod $first,
        ?BudgetPeriod $last,
        string $amount,
    ): Budget {
        return new Budget($account, $user, $type, $frequency, $category, AmountSchedule::initial($first, $last, $amount));
    }

    /**
     * Presupuesto con el que chocaría uno con estos datos (mismo tipo, ámbito y
     * frecuencia, vigencias solapadas), o null si no hay ninguno.
     */
    public function findClash(Account $account, string $type, Frequency $frequency, ?Category $category, BudgetPeriod $first, ?BudgetPeriod $last): ?Budget
    {
        return $this->budgets->findOverlapping($account, $type, $frequency, $category, $first, $last)[0] ?? null;
    }

    /** Los tramos tras aplicar un cambio de importe con su alcance. */
    public function scheduleWith(Budget $budget, string $scope, BudgetPeriod $period, string $amount): AmountSchedule
    {
        $schedule = $budget->getSchedule();

        return match ($scope) {
            BudgetAmountType::SCOPE_ONCE => $schedule->applyOnce($period, $amount),
            BudgetAmountType::SCOPE_FROM => $schedule->applyFrom($period, $amount),
            BudgetAmountType::SCOPE_ALL  => $schedule->applyAll($amount),
            default => throw new \InvalidArgumentException(sprintf('Alcance no válido: %s', $scope)),
        };
    }

    /**
     * Qué va a pasar si se aplica el cambio: datos para budget/_preview.html.twig.
     *
     * @return array{
     *     unchanged: bool,
     *     scope: string,
     *     period: BudgetPeriod,
     *     amount: string,
     *     returnTo: ?string,
     *     current: ?array{period: BudgetPeriod, spent: string, outcome: BudgetOutcome, percent: int},
     *     discarded: list<AmountChange>,
     *     flips: list<array{period: BudgetPeriod, success: bool}>,
     *     closedCount: int,
     * }
     */
    public function preview(Budget $budget, string $scope, BudgetPeriod $period, string $amount, \DateTimeImmutable $today): array
    {
        $old = $budget->getSchedule();
        $new = $this->scheduleWith($budget, $scope, $period, $amount);
        $current = $budget->periodAt($today);

        // «Solo»: a qué importe se vuelve en el periodo siguiente (si sigue en vigencia).
        $returnTo = null;
        if ($scope === BudgetAmountType::SCOPE_ONCE && $old->covers($period->next())) {
            $returnTo = $old->amountAt($period->next());
        }

        // Cómo queda el periodo en curso, si el cambio le afecta.
        $currentEffect = null;
        $touchesCurrent = $scope === BudgetAmountType::SCOPE_ALL || $period->equals($current);
        if ($touchesCurrent && $old->covers($current)) {
            [$spent] = $this->evaluator->spentIn($budget, $current);
            $limit = $new->amountAt($current);
            $percent = (int) round((float) $spent / (float) $limit * 100);
            $currentEffect = [
                'period'  => $current,
                'spent'   => $spent,
                'percent' => $percent,
                'outcome' => match (true) {
                    !$budget->isExpense() => bccomp($spent, $limit, 2) >= 0 ? BudgetOutcome::GoalReached : BudgetOutcome::GoalPending,
                    bccomp($spent, $limit, 2) > 0 => BudgetOutcome::Over,
                    $percent >= BudgetEvaluator::NEAR_PERCENT => BudgetOutcome::Near,
                    default => BudgetOutcome::OnTrack,
                },
            ];
        }

        // Cambios que se pierden (el primer rango nunca: lo sustituye el propio cambio).
        $discarded = array_values(array_filter($old->changes(), fn (AmountChange $change) => !$change->isFirst && match ($scope) {
            BudgetAmountType::SCOPE_ALL  => true,
            BudgetAmountType::SCOPE_FROM => $change->from->compare($period) >= 0,
            default                      => $change->from->equals($period),
        }));

        // Con «todo el historial», qué periodos cerrados cambian de resultado.
        $flips = [];
        $closedCount = 0;
        if ($scope === BudgetAmountType::SCOPE_ALL) {
            foreach ($this->evaluator->closedHistory($budget, $today) as ['period' => $closed, 'spent' => $spent]) {
                $closedCount++;
                $before = BudgetEvaluator::isSuccess($budget, $spent, $old->amountAt($closed) ?? '0');
                $after = BudgetEvaluator::isSuccess($budget, $spent, $amount);
                if ($before !== $after) {
                    $flips[] = ['period' => $closed, 'success' => $after];
                }
            }
        }

        return [
            'unchanged'   => $new->equals($old),
            'scope'       => $scope,
            'period'      => $period,
            'amount'      => bcadd($amount, '0', 2),
            'returnTo'    => $returnTo,
            'current'     => $currentEffect,
            'discarded'   => $discarded,
            'flips'       => $flips,
            'closedCount' => $closedCount,
        ];
    }

    /**
     * Finaliza: deja de contar al acabar el periodo en curso y descarta los
     * tramos posteriores. Si aún no había empezado, no hay historial que guardar
     * y se elimina: devuelve false para que el llamador lo borre.
     */
    public function finalize(Budget $budget, \DateTimeImmutable $today): bool
    {
        if ($budget->isUpcoming($today)) {
            return false;
        }

        // Si ya termina en este periodo o antes, no hay nada que adelantar (y
        // endingAt() lo alargaría).
        $current = $budget->periodAt($today);
        $last = $budget->lastPeriod();
        if ($last !== null && $last->compare($current) <= 0) {
            return true;
        }

        $budget->applySchedule($budget->getSchedule()->endingAt($current));

        return true;
    }
}
