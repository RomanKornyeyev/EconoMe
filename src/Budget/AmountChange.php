<?php

namespace App\Budget;

/**
 * Un cambio de importe tal y como se enseña en el historial del presupuesto.
 *
 * No se guarda: se deduce de los tramos (AmountSchedule::changes()). Hay dos
 * clases:
 *  - **rango** (`base`): un importe que rige desde un periodo hasta el siguiente
 *    cambio de base, o sin fin;
 *  - **puntual** (`once`): un solo periodo con otro importe, tras el que se
 *    vuelve al anterior. En los tramos son dos: el del periodo y el de vuelta.
 */
final class AmountChange
{
    /**
     * @param list<string> $keys claves de los tramos que lo forman (uno, o dos si es puntual)
     */
    public function __construct(
        public readonly bool $once,
        public readonly BudgetPeriod $from,
        /** Último periodo del rango (null = sin fin). En un puntual, el mismo `from`. */
        public readonly ?BudgetPeriod $to,
        public readonly string $amount,
        /** Puntual: el importe al que se vuelve después. */
        public readonly ?string $baseAmount,
        public readonly array $keys,
        /** El rango con el que empieza el presupuesto: no se puede quitar. */
        public readonly bool $isFirst,
    ) {}

    /** «Solo dic 2026», «Desde jul 2026», «Ene 2026 – jun 2026». */
    public function label(): string
    {
        if ($this->once) {
            return 'Solo ' . $this->from->shortLabel();
        }
        if ($this->to === null) {
            return 'Desde ' . $this->from->shortLabel();
        }
        if ($this->to->equals($this->from)) {
            return self::ucfirst($this->from->shortLabel());
        }

        return self::ucfirst($this->from->shortLabel()) . ' – ' . $this->to->shortLabel();
    }

    /** 'current' (vigente), 'upcoming' (programado) o 'past' (anterior), respecto al periodo en curso. */
    public function status(BudgetPeriod $current): string
    {
        if ($this->from->compare($current) > 0) {
            return 'upcoming';
        }
        if ($this->to !== null && $this->to->compare($current) < 0) {
            return 'past';
        }

        return 'current';
    }

    /** Se puede quitar si empieza en el periodo en curso o después, y no es el primero. */
    public function isRemovable(BudgetPeriod $current): bool
    {
        return !$this->isFirst && $this->from->compare($current) >= 0;
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
