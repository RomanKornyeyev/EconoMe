<?php

namespace App\Budget;

use App\Enum\Frequency;

/**
 * Importe de un presupuesto a lo largo del tiempo: una serie de tramos
 * (desde, importe). El límite de un periodo es el del último tramo que empieza
 * en ese periodo o antes.
 *
 * Así el importe puede cambiar sin reescribir el pasado ni partir el presupuesto
 * en varios: cada periodo cerrado conserva el límite que tenía.
 *
 * Es el único sitio que garantiza las invariantes de los tramos
 * (claude/presupuestos.md §4.4). Cada operación devuelve otro horario ya
 * normalizado:
 *  1. hay al menos un tramo, y el primero empieza en el periodo inicial;
 *  2. cada tramo empieza en el primer día de un periodo de la frecuencia;
 *  3. como mucho, un tramo por periodo;
 *  4. no hay dos tramos seguidos con el mismo importe (se fusionan);
 *  5. no hay tramos después del último periodo de la vigencia.
 *
 * Los importes van como cadenas con dos decimales (bcmath), igual que la columna.
 */
final class AmountSchedule
{
    /** @var array<string, string> clave del periodo ('Y-m-d') => importe, en orden */
    private readonly array $steps;

    /**
     * @param array<string, string> $steps clave del periodo => importe
     */
    public function __construct(
        public readonly BudgetPeriod $first,
        public readonly ?BudgetPeriod $last,
        array $steps,
    ) {
        $this->steps = $this->normalize($steps);
    }

    /** Horario de un presupuesto recién creado: un único tramo desde el inicio. */
    public static function initial(BudgetPeriod $first, ?BudgetPeriod $last, string $amount): self
    {
        return new self($first, $last, [$first->key() => $amount]);
    }

    public function frequency(): Frequency
    {
        return $this->first->frequency;
    }

    /** @return array<string, string> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function equals(self $other): bool
    {
        return $this->steps === $other->steps
            && $this->first->equals($other->first)
            && ($this->last === null ? $other->last === null : ($other->last !== null && $this->last->equals($other->last)));
    }

    /** Límite de un periodo. Null si es anterior al inicio o posterior al fin. */
    public function amountAt(BudgetPeriod $period): ?string
    {
        if (!$this->covers($period)) {
            return null;
        }

        return $this->amountAtKey($period->key());
    }

    /** El periodo está dentro de la vigencia. */
    public function covers(BudgetPeriod $period): bool
    {
        return $period->compare($this->first) >= 0
            && ($this->last === null || $period->compare($this->last) <= 0);
    }

    // ── Editar el importe: los tres alcances ──────────────────────────────

    /**
     * Solo ese periodo: lo pone a `amount` y, si no había tramo en el siguiente,
     * añade uno que vuelve al importe que regía allí (salvo que sea el último
     * periodo de la vigencia).
     */
    public function applyOnce(BudgetPeriod $period, string $amount): self
    {
        $this->assertWithin($period);
        $next = $period->next();
        $steps = $this->steps;
        $returnTo = $this->amountAtKey($next->key());

        $steps[$period->key()] = $amount;
        if (!isset($this->steps[$next->key()]) && ($this->last === null || $next->compare($this->last) <= 0)) {
            $steps[$next->key()] = $returnTo;
        }

        return new self($this->first, $this->last, $steps);
    }

    /** Desde ese periodo en adelante: descarta los tramos posteriores. */
    public function applyFrom(BudgetPeriod $period, string $amount): self
    {
        $this->assertWithin($period);
        $steps = array_filter($this->steps, fn (string $key) => $key < $period->key(), ARRAY_FILTER_USE_KEY);
        $steps[$period->key()] = $amount;

        return new self($this->first, $this->last, $steps);
    }

    /** Todo el historial: un único tramo desde el inicio. Reescribe los periodos cerrados. */
    public function applyAll(string $amount): self
    {
        return self::initial($this->first, $this->last, $amount);
    }

    /**
     * El mismo horario con la vigencia terminando en `last` (finalizar): se
     * descartan los tramos posteriores.
     */
    public function endingAt(BudgetPeriod $last): self
    {
        if ($last->compare($this->first) < 0) {
            throw new \InvalidArgumentException('El fin no puede ser anterior al inicio.');
        }

        return new self($this->first, $last, $this->steps);
    }

    // ── Historial de cambios ──────────────────────────────────────────────

    /**
     * Los cambios tal y como se enseñan: rangos y puntuales, en orden.
     *
     * Un tramo es puntual si el siguiente empieza justo un periodo después y
     * vuelve al importe anterior.
     *
     * @return list<AmountChange>
     */
    public function changes(): array
    {
        ['base' => $base, 'once' => $once] = $this->structure();
        $changes = array_merge($base, $once);
        usort($changes, fn (AmountChange $a, AmountChange $b) => $a->from->compare($b->from));

        return $changes;
    }

    /** El cambio puntual de ese periodo, si lo es. */
    public function onceChangeAt(BudgetPeriod $period): ?AmountChange
    {
        foreach ($this->structure()['once'] as $change) {
            if ($change->from->equals($period)) {
                return $change;
            }
        }

        return null;
    }

    /** El primer cambio que empieza después de `period` (y del inicio): el «próximo cambio» de la lista. */
    public function upcomingChange(BudgetPeriod $period): ?AmountChange
    {
        $after = $period->compare($this->first) >= 0 ? $period : $this->first;
        foreach ($this->changes() as $change) {
            if ($change->from->compare($after) > 0) {
                return $change;
            }
        }

        return null;
    }

    /**
     * Sin ese cambio. Un puntual se va con su tramo de vuelta. Al quitar un rango,
     * los puntuales que caían dentro vuelven al importe anterior al rango (se
     * reescribe su tramo de vuelta), para que no apunten al importe quitado.
     */
    public function without(AmountChange $change): self
    {
        if ($change->isFirst) {
            throw new \InvalidArgumentException('El primer importe del presupuesto no se puede quitar.');
        }

        $steps = array_diff_key($this->steps, array_flip($change->keys));

        if (!$change->once) {
            $previous = $this->amountAtKey($change->from->previous()->key());
            foreach ($this->structure()['once'] as $inner) {
                $inside = $inner->from->compare($change->from) > 0
                    && ($change->to === null || $inner->from->compare($change->to) <= 0);
                if ($inside) {
                    $steps[$inner->keys[1]] = $previous;
                }
            }
        }

        return new self($this->first, $this->last, $steps);
    }

    /** El cambio cuya clave inicial es `key`, para localizarlo desde un formulario. */
    public function changeStartingAt(string $key): ?AmountChange
    {
        foreach ($this->changes() as $change) {
            if ($change->from->key() === $key) {
                return $change;
            }
        }

        return null;
    }

    // ── Interno ───────────────────────────────────────────────────────────

    /** @return array{base: list<AmountChange>, once: list<AmountChange>} */
    private function structure(): array
    {
        $frequency = $this->frequency();
        $keys = array_keys($this->steps);
        $baseRaw = [];
        $once = [];

        for ($i = 0, $n = \count($keys); $i < $n; $i++) {
            $key = $keys[$i];
            $amount = $this->steps[$key];
            $previous = end($baseRaw) ?: null;
            $nextKey = $keys[$i + 1] ?? null;
            $period = BudgetPeriod::containing($frequency, new \DateTimeImmutable($key));

            if ($previous !== null && $nextKey !== null
                && $nextKey === $period->next()->key()
                && bccomp($this->steps[$nextKey], $previous['amount'], 2) === 0
            ) {
                $once[] = new AmountChange(true, $period, $period, $amount, $previous['amount'], [$key, $nextKey], false);
                $i++;
                continue;
            }
            if ($previous !== null && bccomp($previous['amount'], $amount, 2) === 0) {
                continue;
            }
            $baseRaw[] = ['period' => $period, 'amount' => $amount, 'key' => $key];
        }

        $base = [];
        foreach ($baseRaw as $i => $raw) {
            $to = isset($baseRaw[$i + 1]) ? $baseRaw[$i + 1]['period']->previous() : $this->last;
            $base[] = new AmountChange(false, $raw['period'], $to, $raw['amount'], null, [$raw['key']], $i === 0);
        }

        return ['base' => $base, 'once' => $once];
    }

    private function amountAtKey(string $key): ?string
    {
        $amount = null;
        foreach ($this->steps as $from => $value) {
            if ($from > $key) {
                break;
            }
            $amount = $value;
        }

        return $amount;
    }

    private function assertWithin(BudgetPeriod $period): void
    {
        if ($period->frequency !== $this->frequency() || !$this->covers($period)) {
            throw new \InvalidArgumentException('El periodo está fuera de la vigencia del presupuesto.');
        }
    }

    /**
     * @param array<string, string> $steps
     * @return array<string, string>
     */
    private function normalize(array $steps): array
    {
        $frequency = $this->first->frequency;
        if ($this->last !== null && $this->last->compare($this->first) < 0) {
            throw new \InvalidArgumentException('El fin no puede ser anterior al inicio.');
        }

        $clean = [];
        foreach ($steps as $key => $amount) {
            $key = (string) $key;
            $period = BudgetPeriod::containing($frequency, new \DateTimeImmutable($key));
            if ($period->key() !== $key) {
                throw new \InvalidArgumentException(sprintf('El tramo %s no empieza en un periodo.', $key));
            }
            if ($this->last !== null && $key > $this->last->key()) {
                continue; // invariante 5
            }
            if (bccomp($amount, '0', 2) <= 0) {
                throw new \InvalidArgumentException('El importe tiene que ser mayor que 0.');
            }
            $clean[$key] = bcadd($amount, '0', 2);
        }
        ksort($clean, SORT_STRING);

        if (array_key_first($clean) !== $this->first->key()) {
            throw new \InvalidArgumentException('El primer tramo tiene que empezar en el periodo inicial.');
        }

        $merged = [];
        $previous = null;
        foreach ($clean as $key => $amount) {
            if ($previous !== null && bccomp($previous, $amount, 2) === 0) {
                continue; // invariante 4
            }
            $merged[$key] = $amount;
            $previous = $amount;
        }

        return $merged;
    }
}
