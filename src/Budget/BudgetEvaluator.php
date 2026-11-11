<?php

namespace App\Budget;

use App\Entity\Account;
use App\Entity\Budget;
use App\Enum\Frequency;
use App\Repository\TransactionRepository;

/**
 * Evalúa presupuestos: cuánto se lleva en cada periodo y cómo va o cómo quedó.
 *
 * Lo llevado sale de una sola consulta por cuenta y rango
 * (TransactionRepository::dailyTotalsByCategory), repartida aquí en periodos.
 * Solo cuentan los movimientos del mismo tipo que el presupuesto; los de toda la
 * cuenta incluyen también los que no tienen categoría.
 *
 * Devuelve datos (BudgetRow, BudgetPanel), no textos: los compone la plantilla.
 */
class BudgetEvaluator
{
    /** % a partir del cual un límite de gasto está «cerca». */
    public const NEAR_PERCENT = 80;

    public function __construct(
        private TransactionRepository $transactions,
    ) {}

    /**
     * El panel del dashboard para el mes (o el año, con month = 0) que se mira.
     *
     * Qué periodo mira cada presupuesto (claude/presupuestos.md §5.1):
     *  - vista de mes: el periodo que contiene el día 1 del mes (el mes, el
     *    trimestre…). Los semanales, la semana en curso si es el mes actual, las
     *    semanas que empiezan en el mes si ya pasó, o la primera si está por venir;
     *  - vista de año: todos sus periodos del año (los semanales, los que empiezan
     *    en él), resumidos en cumplidos sobre cerrados.
     *
     * @param list<Budget> $budgets
     */
    public function panel(Account $account, array $budgets, int $year, int $month, \DateTimeImmutable $today): BudgetPanel
    {
        $today = $today->setTime(0, 0);
        $yearView = $month < 1 || $month > 12;
        $viewFrom = $yearView ? new \DateTimeImmutable("$year-01-01") : new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $viewTo = $yearView ? new \DateTimeImmutable("$year-12-31") : $viewFrom->modify('last day of this month');
        $tense = $viewTo < $today ? 'past' : ($viewFrom > $today ? 'future' : 'current');

        $plans = [];
        foreach ($budgets as $budget) {
            $schedule = $budget->getSchedule();
            $periods = array_values(array_filter(
                $this->periodsInView($budget, $yearView, $viewFrom, $viewTo, $tense, $today),
                fn (BudgetPeriod $period) => $schedule->covers($period),
            ));
            if ($periods !== []) {
                $plans[] = [$budget, $schedule, $periods];
            }
        }

        $entries = $this->entriesFor($account, $plans);

        $rows = [];
        foreach ($plans as [$budget, $schedule, $periods]) {
            $own = $entries[spl_object_id($budget)];
            $rows[] = \count($periods) === 1
                ? $this->single($budget, $schedule, $periods[0], $own, $today, $tense === 'past')
                : $this->summary($budget, $schedule, $periods, $own, $today);
        }

        usort($rows, fn (BudgetRow $a, BudgetRow $b) => [$a->outcome->rank(), $a->budget->getName()] <=> [$b->outcome->rank(), $b->budget->getName()]);

        return new BudgetPanel(
            $tense,
            $yearView,
            $yearView ? (string) $year : BudgetPeriod::containing(Frequency::Monthly, $viewFrom)->label(),
            $rows,
            \count($budgets),
        );
    }

    /**
     * Cómo va cada presupuesto en su periodo en curso (la lista de la página).
     * Si aún no ha empezado, su primer periodo; si ya terminó, el último.
     *
     * @param list<Budget> $budgets
     * @return array<int, BudgetRow> id del presupuesto => fila
     */
    public function current(Account $account, array $budgets, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $plans = [];
        foreach ($budgets as $budget) {
            $period = $budget->periodAt($today);
            $schedule = $budget->getSchedule();
            if (!$schedule->covers($period)) {
                $period = $budget->isUpcoming($today) ? $schedule->first : $schedule->last;
            }
            $plans[] = [$budget, $schedule, [$period]];
        }

        $entries = $this->entriesFor($account, $plans);

        $rows = [];
        foreach ($plans as [$budget, $schedule, $periods]) {
            $rows[$budget->getId()] = $this->single($budget, $schedule, $periods[0], $entries[spl_object_id($budget)], $today, false);
        }

        return $rows;
    }

    /**
     * Lo llevado en cada periodo cerrado de la vigencia, para el resumen de
     * «todo el historial» (qué periodos cambiarían de resultado).
     *
     * @return list<array{period: BudgetPeriod, spent: string}>
     */
    public function closedHistory(Budget $budget, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $first = $budget->firstPeriod();
        $lastClosed = $budget->periodAt($today)->previous();
        if ($budget->lastPeriod() !== null && $budget->lastPeriod()->compare($lastClosed) < 0) {
            $lastClosed = $budget->lastPeriod();
        }
        if ($lastClosed->compare($first) < 0) {
            return [];
        }

        $periods = BudgetPeriod::overlapping($budget->getFrequency(), $first->start, $lastClosed->end);
        $entries = $this->entriesFor($budget->getAccount(), [[$budget, $budget->getSchedule(), $periods]])[spl_object_id($budget)];

        return array_map(fn (BudgetPeriod $period) => [
            'period' => $period,
            'spent'  => $this->sum($entries, $period)[0],
        ], $periods);
    }

    /** Lo llevado en un periodo y la parte que viene de recurrentes. */
    public function spentIn(Budget $budget, BudgetPeriod $period): array
    {
        $entries = $this->entriesFor($budget->getAccount(), [[$budget, $budget->getSchedule(), [$period]]])[spl_object_id($budget)];

        return $this->sum($entries, $period);
    }

    /** ¿Se cumple con ese límite? Gasto: no pasarse. Meta: llegar. */
    public static function isSuccess(Budget $budget, string $spent, string $limit): bool
    {
        return $budget->isExpense()
            ? bccomp($spent, $limit, 2) <= 0
            : bccomp($spent, $limit, 2) >= 0;
    }

    // ── Interno ───────────────────────────────────────────────────────────

    /** @return list<BudgetPeriod> */
    private function periodsInView(Budget $budget, bool $yearView, \DateTimeImmutable $from, \DateTimeImmutable $to, string $tense, \DateTimeImmutable $today): array
    {
        $frequency = $budget->getFrequency();
        $weekly = $frequency === Frequency::Weekly;

        if ($yearView) {
            return $weekly
                ? BudgetPeriod::startingWithin($frequency, $from, $to)
                : BudgetPeriod::overlapping($frequency, $from, $to);
        }
        if (!$weekly) {
            return [BudgetPeriod::containing($frequency, $from)];
        }

        return match ($tense) {
            'current' => [BudgetPeriod::containing($frequency, $today)],
            'past'    => BudgetPeriod::startingWithin($frequency, $from, $to),
            default   => \array_slice(BudgetPeriod::startingWithin($frequency, $from, $to), 0, 1),
        };
    }

    private function single(Budget $budget, AmountSchedule $schedule, BudgetPeriod $period, array $entries, \DateTimeImmutable $today, bool $closedView): BudgetRow
    {
        $limit = $schedule->amountAt($period) ?? '0.00';
        [$spent, $recurring] = $this->sum($entries, $period);
        $usual = $schedule->onceChangeAt($period)?->baseAmount;
        $common = [
            'budget' => $budget, 'from' => $period, 'to' => $period,
            'limit' => $limit, 'spent' => $spent, 'recurring' => $recurring, 'usualAmount' => $usual,
        ];

        if ($period->isFuture($today)) {
            return new BudgetRow(...$common, outcome: BudgetOutcome::Pending);
        }

        if ($period->isClosed($today)) {
            $success = self::isSuccess($budget, $spent, $limit);
            $outcome = $budget->isExpense()
                ? ($success ? BudgetOutcome::Met : BudgetOutcome::Exceeded)
                : ($success ? BudgetOutcome::GoalReached : BudgetOutcome::GoalMissed);

            return new BudgetRow(...$common, outcome: $outcome, met: $success ? 1 : 0, closed: 1, failed: $success ? [] : [$period]);
        }

        if ($budget->isExpense()) {
            $percent = bccomp($limit, '0', 2) > 0 ? (float) $spent / (float) $limit * 100 : 0;
            $outcome = match (true) {
                bccomp($spent, $limit, 2) > 0   => BudgetOutcome::Over,
                $percent >= self::NEAR_PERCENT  => BudgetOutcome::Near,
                default                         => BudgetOutcome::OnTrack,
            };
        } else {
            $outcome = bccomp($spent, $limit, 2) >= 0 ? BudgetOutcome::GoalReached : BudgetOutcome::GoalPending;
        }

        // Un trimestre aún abierto visto desde un mes cerrado: se avisa si ya se
        // ha pasado, pero si no, solo «en curso»; aún no se puede juzgar.
        if ($closedView && \in_array($outcome, [BudgetOutcome::OnTrack, BudgetOutcome::Near, BudgetOutcome::GoalPending], true)) {
            $outcome = BudgetOutcome::Running;
        }

        return new BudgetRow(
            ...$common,
            outcome: $outcome,
            marker: round($period->elapsedFraction($today) * 100, 1),
            daysLeft: $period->daysLeft($today),
        );
    }

    /** @param list<BudgetPeriod> $periods */
    private function summary(Budget $budget, AmountSchedule $schedule, array $periods, array $entries, \DateTimeImmutable $today): BudgetRow
    {
        $met = 0;
        $failed = [];
        $closed = array_values(array_filter($periods, fn (BudgetPeriod $p) => $p->isClosed($today)));

        // Nada cerrado aún (empezó este mes o está por venir): se enseña su periodo
        // en curso o, si no, el primero.
        if ($closed === []) {
            $open = array_values(array_filter($periods, fn (BudgetPeriod $p) => !$p->isFuture($today)));

            return $this->single($budget, $schedule, $open[0] ?? $periods[0], $entries, $today, false);
        }

        foreach ($closed as $period) {
            if (self::isSuccess($budget, $this->sum($entries, $period)[0], $schedule->amountAt($period) ?? '0')) {
                $met++;
            } else {
                $failed[] = $period;
            }
        }

        $ratio = $met / \count($closed);
        $outcome = match (true) {
            $ratio >= 1   => BudgetOutcome::AllMet,
            $ratio >= 0.8 => BudgetOutcome::MostlyMet,
            $ratio >= 0.5 => BudgetOutcome::PartlyMet,
            default       => BudgetOutcome::RarelyMet,
        };

        return new BudgetRow(
            budget: $budget,
            outcome: $outcome,
            from: $periods[0],
            to: $periods[\count($periods) - 1],
            summary: true,
            met: $met,
            closed: \count($closed),
            failed: $failed,
        );
    }

    /**
     * Movimientos de cada plan ya filtrados por su presupuesto (tipo y ámbito), de
     * una sola consulta sobre el rango que cubren todos.
     *
     * @param list<array{0: Budget, 1: AmountSchedule, 2: list<BudgetPeriod>}> $plans
     * @return array<int, list<array{date: string, total: string, recurring: string}>> spl_object_id del presupuesto => movimientos
     */
    private function entriesFor(Account $account, array $plans): array
    {
        if ($plans === []) {
            return [];
        }

        $from = null;
        $to = null;
        foreach ($plans as [, , $periods]) {
            foreach ($periods as $period) {
                $from = $from === null || $period->start < $from ? $period->start : $from;
                $to = $to === null || $period->end > $to ? $period->end : $to;
            }
        }

        $rows = $this->transactions->dailyTotalsByCategory($account, $from, $to);

        $entries = [];
        foreach ($plans as [$budget]) {
            $categoryId = $budget->getCategory()?->getId();
            $entries[spl_object_id($budget)] = array_values(array_filter(
                $rows,
                fn (array $row) => $row['type'] === $budget->getType()
                    && ($budget->isAccountWide() || $row['categoryId'] === $categoryId),
            ));
        }

        return $entries;
    }

    /**
     * @param list<array{date: string, total: string, recurring: string}> $entries
     * @return array{0: string, 1: string} llevado y parte de recurrentes
     */
    private function sum(array $entries, BudgetPeriod $period): array
    {
        $start = $period->start->format('Y-m-d');
        $end = $period->end->format('Y-m-d');
        $spent = '0.00';
        $recurring = '0.00';
        foreach ($entries as $entry) {
            if ($entry['date'] >= $start && $entry['date'] <= $end) {
                $spent = bcadd($spent, $entry['total'], 2);
                $recurring = bcadd($recurring, $entry['recurring'], 2);
            }
        }

        return [$spent, $recurring];
    }
}
