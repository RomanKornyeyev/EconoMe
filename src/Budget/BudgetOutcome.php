<?php

namespace App\Budget;

/**
 * Cómo va (o cómo quedó) un presupuesto en lo que se está mirando.
 *
 * El estado va por umbral, no por ritmo (claude/presupuestos.md §4.6): con un
 * gasto fijo el día 1 (la hipoteca), ir por delante del ritmo no significa ir
 * mal. La marca de «hoy» de la barra es solo una referencia visual.
 */
enum BudgetOutcome: string
{
    // Periodo en curso
    case OnTrack = 'on_track';         // gasto por debajo del 80 %
    case Near = 'near';                // gasto en el 80 % o más
    case Over = 'over';                // gasto por encima del límite
    case GoalPending = 'goal_pending'; // meta aún no alcanzada
    case Running = 'running';          // periodo largo aún abierto, visto desde un mes cerrado

    // Periodo cerrado (la meta alcanzada vale también en curso)
    case Met = 'met';
    case Exceeded = 'exceeded';
    case GoalReached = 'goal_reached';
    case GoalMissed = 'goal_missed';

    // Periodo futuro
    case Pending = 'pending';

    // Varios periodos cerrados: semanas de un mes pasado, o la vista de año
    case AllMet = 'all_met';
    case MostlyMet = 'mostly_met';     // 80 % o más
    case PartlyMet = 'partly_met';     // 50 % o más
    case RarelyMet = 'rarely_met';     // menos del 50 %

    /** Orden del panel: lo urgente arriba. */
    public function rank(): int
    {
        return match ($this) {
            self::Over, self::Exceeded, self::GoalMissed, self::RarelyMet => 0,
            self::Near, self::PartlyMet                                   => 1,
            self::OnTrack, self::GoalPending, self::Running               => 2,
            self::Met, self::GoalReached, self::AllMet, self::MostlyMet   => 3,
            self::Pending                                                 => 4,
        };
    }

    /** Color de Bootstrap: success, warning, danger o secondary. */
    public function tone(): string
    {
        return match ($this->rank()) {
            0       => 'danger',
            1       => 'warning',
            4       => 'secondary',
            default => $this === self::Running ? 'secondary' : 'success',
        };
    }

    public function icon(): string
    {
        return match ($this->rank()) {
            0       => 'fa-circle-xmark',
            1       => 'fa-triangle-exclamation',
            4       => 'fa-clock',
            default => $this === self::Running ? 'fa-clock' : 'fa-circle-check',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::OnTrack     => 'Vas bien',
            self::Near        => 'Cerca del límite',
            self::Over        => 'Te has pasado',
            self::GoalPending => 'En camino',
            self::Running     => 'En curso',
            self::Met         => '¡Cumplido!',
            self::Exceeded    => 'Te pasaste',
            self::GoalReached => '¡Meta alcanzada!',
            self::GoalMissed  => 'No alcanzada',
            self::Pending     => 'Aún no ha empezado',
            self::AllMet      => '¡Todos cumplidos!',
            self::MostlyMet   => 'Casi todos cumplidos',
            self::PartlyMet   => 'Algunos sin cumplir',
            self::RarelyMet   => 'Casi ninguno cumplido',
        };
    }

    /** Cuenta como «va bien» en el KPI del periodo en curso. */
    public function isGood(): bool
    {
        return \in_array($this->rank(), [2, 3], true);
    }

    /** Cumplido del todo: el «3 de 5 cumplidos» de un periodo cerrado. */
    public function isSuccess(): bool
    {
        return \in_array($this, [self::Met, self::GoalReached, self::AllMet], true);
    }
}
