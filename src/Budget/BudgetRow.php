<?php

namespace App\Budget;

use App\Entity\Budget;

/**
 * Una fila del panel de presupuestos del dashboard: cómo va un presupuesto en lo
 * que se está mirando. Datos, no presentación: los textos los compone la
 * plantilla (components/_budget_panel.html.twig).
 *
 * Dos formas:
 *  - **un periodo** (`summary` false): límite, llevado, % transcurrido…;
 *  - **resumen** de varios periodos (las semanas de un mes cerrado, o la vista
 *    de año): cuántos cerrados se cumplieron y cuáles no.
 *
 * `met` y `closed` se rellenan también en un periodo cerrado (1/1 o 0/1), para
 * que los totales de la vista de año salgan de sumar filas sin distinguir.
 */
final class BudgetRow
{
    /**
     * @param list<BudgetPeriod> $failed periodos cerrados que no se cumplieron (resumen)
     */
    public function __construct(
        public readonly Budget $budget,
        public readonly BudgetOutcome $outcome,
        /** Primer y último periodo mirados (el mismo si es uno). */
        public readonly BudgetPeriod $from,
        public readonly BudgetPeriod $to,
        public readonly bool $summary = false,
        public readonly ?string $limit = null,
        public readonly string $spent = '0.00',
        /** Parte de lo llevado que viene de recurrentes. */
        public readonly string $recurring = '0.00',
        /** % transcurrido del periodo; null si no está abierto. */
        public readonly ?int $elapsed = null,
        /** Días que quedan, hoy incluido. */
        public readonly int $daysLeft = 0,
        /** Importe habitual si este periodo tiene un importe puntual. */
        public readonly ?string $usualAmount = null,
        public readonly int $met = 0,
        public readonly int $closed = 0,
        public readonly array $failed = [],
    ) {}

    /** Llevado sobre el límite (un periodo) o cumplidos sobre cerrados (resumen), en %. */
    public function percent(): int
    {
        if ($this->summary) {
            return $this->closed > 0 ? (int) round($this->met / $this->closed * 100) : 0;
        }
        if ($this->limit === null || bccomp($this->limit, '0', 2) <= 0) {
            return 0;
        }

        return (int) round((float) $this->spent / (float) $this->limit * 100);
    }

    /** Ancho de la barra: el % sin pasar de 100. */
    public function barPercent(): int
    {
        return min(100, $this->percent());
    }

    /**
     * Dónde cae la línea del límite (o la meta) en la barra, en %. Sin superarlo,
     * al final (100). Superado, la barra pasa a ser lo llevado y el límite queda
     * dentro (110 % → 90,9). Null en un resumen: no hay límite que marcar.
     */
    public function limitMark(): ?float
    {
        if ($this->summary || $this->limit === null || bccomp($this->limit, '0', 2) <= 0) {
            return null;
        }
        if (bccomp($this->spent, $this->limit, 2) <= 0) {
            return 100.0;
        }

        return round((float) $this->limit / (float) $this->spent * 100, 1);
    }

    /** Límite menos llevado (negativo si se ha pasado). */
    public function remaining(): string
    {
        return bcsub($this->limit ?? '0', $this->spent, 2);
    }

    /** Lo que se puede gastar al día lo que queda de periodo. Null si no queda nada. */
    public function perDay(): ?string
    {
        if ($this->daysLeft <= 0 || bccomp($this->remaining(), '0', 2) <= 0) {
            return null;
        }

        return bcdiv($this->remaining(), (string) $this->daysLeft, 2);
    }

    public function isOpen(): bool
    {
        return $this->elapsed !== null;
    }

    /** Un solo periodo: el que se mira. */
    public function period(): BudgetPeriod
    {
        return $this->from;
    }
}
