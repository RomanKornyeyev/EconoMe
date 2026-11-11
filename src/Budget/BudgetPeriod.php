<?php

namespace App\Budget;

use App\Enum\Frequency;

/**
 * Un periodo de calendario de un presupuesto: la semana (de lunes a domingo), el
 * mes, el trimestre (ene–mar…), el semestre o el año que contiene una fecha.
 *
 * Son periodos de calendario, no anclados a una fecha como los de las
 * recurrentes: así casan con el selector del dashboard y con «este mes». Todo lo
 * que se fecha en un presupuesto (inicio, fin, tramos) cae en el primer o en el
 * último día de uno de estos periodos.
 *
 * Inmutable. Las fechas van siempre a medianoche.
 */
final class BudgetPeriod
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    private const MONTHS_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private function __construct(
        public readonly Frequency $frequency,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
    ) {}

    /** El periodo de esa frecuencia que contiene la fecha. */
    public static function containing(Frequency $frequency, \DateTimeInterface $date): self
    {
        $day   = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        $year  = (int) $day->format('Y');
        $month = (int) $day->format('n');

        $start = match ($frequency) {
            Frequency::Weekly     => $day->modify('-' . ((int) $day->format('N') - 1) . ' days'),
            Frequency::Monthly    => $day->setDate($year, $month, 1),
            Frequency::Quarterly  => $day->setDate($year, intdiv($month - 1, 3) * 3 + 1, 1),
            Frequency::Semiannual => $day->setDate($year, $month <= 6 ? 1 : 7, 1),
            Frequency::Yearly     => $day->setDate($year, 1, 1),
            Frequency::Daily      => throw new \InvalidArgumentException('Los presupuestos no admiten la frecuencia diaria.'),
        };

        return new self($frequency, $start, self::shift($frequency, $start, 1)->modify('-1 day'));
    }

    /**
     * Periodos de esa frecuencia que tocan el rango [from, to], en orden.
     *
     * @return list<self>
     */
    public static function overlapping(Frequency $frequency, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $to = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);
        $periods = [];
        for ($period = self::containing($frequency, $from); $period->start <= $to; $period = $period->next()) {
            $periods[] = $period;
        }

        return $periods;
    }

    /**
     * Periodos de esa frecuencia que **empiezan** en [from, to]. Así se reparten
     * las semanas entre meses: una semana es del mes en el que cae su lunes.
     *
     * @return list<self>
     */
    public static function startingWithin(Frequency $frequency, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $from = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);

        return array_values(array_filter(
            self::overlapping($frequency, $from, $to),
            fn (self $period) => $period->start >= $from,
        ));
    }

    public function next(): self
    {
        return $this->offset(1);
    }

    public function previous(): self
    {
        return $this->offset(-1);
    }

    /** El periodo n posiciones más adelante (o atrás, si n < 0). */
    public function offset(int $n): self
    {
        return self::containing($this->frequency, self::shift($this->frequency, $this->start, $n));
    }

    /** Clave estable del periodo (su primer día): sirve de índice de los tramos. */
    public function key(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function equals(self $other): bool
    {
        return $this->frequency === $other->frequency && $this->start == $other->start;
    }

    /** <0 si este va antes, 0 si es el mismo, >0 si va después. */
    public function compare(self $other): int
    {
        return $this->start <=> $other->start;
    }

    public function contains(\DateTimeInterface $date): bool
    {
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return $day >= $this->start && $day <= $this->end;
    }

    /** Ya terminó: es anterior a hoy. */
    public function isClosed(\DateTimeInterface $today): bool
    {
        return $this->end < \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
    }

    /** Aún no ha empezado. */
    public function isFuture(\DateTimeInterface $today): bool
    {
        return $this->start > \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    /** Días transcurridos contando hoy (el día 11 de un mes de 30 da 11). */
    public function elapsedDays(\DateTimeInterface $today): int
    {
        $day = \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
        if ($day < $this->start) {
            return 0;
        }
        if ($day > $this->end) {
            return $this->days();
        }

        return (int) $this->start->diff($day)->days + 1;
    }

    /** Días que quedan contando hoy (el día 11 de un mes de 30 da 20). */
    public function daysLeft(\DateTimeInterface $today): int
    {
        $elapsed = $this->elapsedDays($today);

        return $elapsed === 0 ? $this->days() : $this->days() - $elapsed + 1;
    }

    /** Fracción transcurrida, de 0 a 1: la marca de «hoy» de la barra. */
    public function elapsedFraction(\DateTimeInterface $today): float
    {
        return $this->elapsedDays($today) / $this->days();
    }

    /** «semana del 9 nov 2026», «noviembre 2026», «oct–dic 2026», «2026». */
    public function label(): string
    {
        return match ($this->frequency) {
            Frequency::Weekly  => 'semana del ' . $this->dayMonth($this->start) . ' ' . $this->start->format('Y'),
            Frequency::Monthly => self::MONTHS[$this->monthIndex()] . ' ' . $this->start->format('Y'),
            default            => $this->shortLabel(),
        };
    }

    /** Como label() pero con el mes abreviado: «nov 2026», «sem. del 9 nov 2026». */
    public function shortLabel(): string
    {
        $year = $this->start->format('Y');

        return match ($this->frequency) {
            Frequency::Weekly     => 'sem. del ' . $this->dayMonth($this->start) . ' ' . $year,
            Frequency::Monthly    => self::MONTHS_SHORT[$this->monthIndex()] . ' ' . $year,
            Frequency::Quarterly  => ['ene–mar', 'abr–jun', 'jul–sep', 'oct–dic'][intdiv($this->monthIndex(), 3)] . ' ' . $year,
            Frequency::Semiannual => ($this->monthIndex() < 6 ? 'ene–jun' : 'jul–dic') . ' ' . $year,
            default               => $year,
        };
    }

    /** label() para ir tras una preposición: «después de la semana del 9 nov 2026», «… de noviembre 2026». */
    public function labelWithArticle(): string
    {
        return ($this->frequency === Frequency::Weekly ? 'la ' : '') . $this->label();
    }

    /** Sin el año, para listas dentro de un mismo año: «sem. del 12 oct», «oct», «jul–sep». */
    public function labelWithoutYear(): string
    {
        return match ($this->frequency) {
            Frequency::Weekly  => 'sem. del ' . $this->dayMonth($this->start),
            Frequency::Yearly  => $this->start->format('Y'),
            default            => explode(' ', $this->shortLabel())[0],
        };
    }

    /** Rango de días de una semana: «9–15 nov», «30 nov – 6 dic». El resto, shortLabel(). */
    public function rangeLabel(): string
    {
        if ($this->frequency !== Frequency::Weekly) {
            return $this->shortLabel();
        }

        return $this->start->format('n') === $this->end->format('n')
            ? $this->start->format('j') . '–' . $this->dayMonth($this->end)
            : $this->dayMonth($this->start) . ' – ' . $this->dayMonth($this->end);
    }

    private function monthIndex(): int
    {
        return (int) $this->start->format('n') - 1;
    }

    private function dayMonth(\DateTimeImmutable $date): string
    {
        return $date->format('j') . ' ' . self::MONTHS_SHORT[(int) $date->format('n') - 1];
    }

    /** El inicio de periodo n posiciones más allá. Seguro: los inicios son día 1 (o lunes). */
    private static function shift(Frequency $frequency, \DateTimeImmutable $start, int $n): \DateTimeImmutable
    {
        return match ($frequency) {
            Frequency::Weekly     => $start->modify(sprintf('%+d days', 7 * $n)),
            Frequency::Monthly    => $start->modify(sprintf('%+d months', $n)),
            Frequency::Quarterly  => $start->modify(sprintf('%+d months', 3 * $n)),
            Frequency::Semiannual => $start->modify(sprintf('%+d months', 6 * $n)),
            Frequency::Yearly     => $start->modify(sprintf('%+d years', $n)),
            Frequency::Daily      => throw new \InvalidArgumentException('Los presupuestos no admiten la frecuencia diaria.'),
        };
    }
}
