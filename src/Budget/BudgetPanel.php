<?php

namespace App\Budget;

/**
 * El panel de presupuestos del dashboard para el periodo que se mira, y las
 * cifras del KPI que lo resume.
 */
final class BudgetPanel
{
    /**
     * @param list<BudgetRow> $rows ya ordenadas por urgencia
     */
    public function __construct(
        /** 'past', 'current' o 'future': el periodo mirado respecto a hoy. */
        public readonly string $tense,
        public readonly bool $yearView,
        /** «noviembre 2026» o «2026». */
        public readonly string $label,
        public readonly array $rows,
        /** Presupuestos de la cuenta, vigentes o no: distingue «no tienes» de «ninguno aquí». */
        public readonly int $budgetCount,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** Filas que van bien: el «4 de 6 van bien» del periodo en curso. */
    public function goodCount(): int
    {
        return \count(array_filter($this->rows, fn (BudgetRow $row) => $row->outcome->isGood()));
    }

    /**
     * Filas en riesgo o pasadas, para la nota del KPI.
     *
     * @return list<BudgetRow>
     */
    public function atRisk(): array
    {
        return array_values(array_filter($this->rows, fn (BudgetRow $row) => $row->outcome->rank() <= 1));
    }

    /**
     * Filas que ya se pueden juzgar: con algún periodo cerrado. Excluye un
     * trimestre aún abierto visto desde un mes cerrado.
     *
     * @return list<BudgetRow>
     */
    public function evaluated(): array
    {
        return array_values(array_filter($this->rows, fn (BudgetRow $row) => $row->closed > 0));
    }

    /** De las juzgables, las cumplidas del todo: el «3 de 5 cumplidos». */
    public function successCount(): int
    {
        return \count(array_filter($this->evaluated(), fn (BudgetRow $row) => $row->outcome->isSuccess()));
    }

    /** Filas aún en curso vistas desde un periodo cerrado. */
    public function runningCount(): int
    {
        return \count(array_filter($this->rows, fn (BudgetRow $row) => $row->outcome === BudgetOutcome::Running));
    }

    /** Periodos cerrados cumplidos, sumando todas las filas (vista de año). */
    public function periodsMet(): int
    {
        return array_sum(array_map(fn (BudgetRow $row) => $row->met, $this->rows));
    }

    public function periodsClosed(): int
    {
        return array_sum(array_map(fn (BudgetRow $row) => $row->closed, $this->rows));
    }

    /** % de periodos cerrados cumplidos (vista de año). Null si aún no hay ninguno. */
    public function periodsPercent(): ?int
    {
        $closed = $this->periodsClosed();

        return $closed > 0 ? (int) round($this->periodsMet() / $closed * 100) : null;
    }
}
