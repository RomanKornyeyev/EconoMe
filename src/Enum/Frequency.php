<?php

namespace App\Enum;

/**
 * Frecuencias de recurrentes y presupuestos.
 *
 * Los valores son las cadenas que ya guardaba la columna `frequency` de las
 * recurrentes: RecurringTransaction::FREQ_* apuntan aquí, así que hay una sola
 * lista. Lo que NO se comparte es qué periodo cubre cada frecuencia: las
 * recurrentes se anclan a su fecha de inicio (RecurringMaterializer) y los
 * presupuestos, al calendario (App\Budget\BudgetPeriod).
 */
enum Frequency: string
{
    /** Solo para recurrentes antiguas: ya no se ofrece al crear y los presupuestos no la admiten. */
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    /**
     * Las que admite un presupuesto, en el orden del desplegable.
     *
     * @return list<self>
     */
    public static function forBudgets(): array
    {
        return [self::Weekly, self::Monthly, self::Quarterly, self::Semiannual, self::Yearly];
    }

    /** «Semanal», «Mensual»… */
    public function label(): string
    {
        return match ($this) {
            self::Daily      => 'Diario',
            self::Weekly     => 'Semanal',
            self::Monthly    => 'Mensual',
            self::Quarterly  => 'Trimestral',
            self::Semiannual => 'Semestral',
            self::Yearly     => 'Anual',
        };
    }

    /** Adjetivo en minúscula, para «límite semanal», «meta anual». */
    public function adjective(): string
    {
        return mb_strtolower($this->label());
    }

    /** Nombre del periodo: «semana», «mes», «trimestre»… */
    public function unit(): string
    {
        return match ($this) {
            self::Daily      => 'día',
            self::Weekly     => 'semana',
            self::Monthly    => 'mes',
            self::Quarterly  => 'trimestre',
            self::Semiannual => 'semestre',
            self::Yearly     => 'año',
        };
    }

    /** Plural de unit(): «3 de 4 semanas», «9 de 10 meses». */
    public function unitPlural(): string
    {
        return match ($this) {
            self::Daily      => 'días',
            self::Weekly     => 'semanas',
            self::Monthly    => 'meses',
            self::Quarterly  => 'trimestres',
            self::Semiannual => 'semestres',
            self::Yearly     => 'años',
        };
    }

    /** «esta semana», «este mes»… */
    public function current(): string
    {
        return ($this === self::Weekly ? 'esta ' : 'este ') . $this->unit();
    }
}
