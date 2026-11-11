<?php

namespace App\Tests\Budget;

use App\Budget\BudgetPeriod;
use App\Enum\Frequency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Periodos de calendario de los presupuestos: alineación, navegación, días y
 * etiquetas. Los bordes que importan: semanas que cruzan de mes o de año,
 * domingos, y trimestres y semestres naturales.
 */
class BudgetPeriodTest extends TestCase
{
    private static function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    /** @return iterable<string, array{Frequency, string, string, string}> */
    public static function alignment(): iterable
    {
        yield 'semana: miércoles'           => [Frequency::Weekly, '2026-11-11', '2026-11-09', '2026-11-15'];
        yield 'semana: el lunes es el inicio' => [Frequency::Weekly, '2026-11-09', '2026-11-09', '2026-11-15'];
        yield 'semana: el domingo cierra'   => [Frequency::Weekly, '2026-11-15', '2026-11-09', '2026-11-15'];
        yield 'semana que cruza de año'     => [Frequency::Weekly, '2026-01-01', '2025-12-29', '2026-01-04'];
        yield 'mes'                         => [Frequency::Monthly, '2026-11-11', '2026-11-01', '2026-11-30'];
        yield 'mes de febrero bisiesto'     => [Frequency::Monthly, '2028-02-15', '2028-02-01', '2028-02-29'];
        yield 'trimestre natural'           => [Frequency::Quarterly, '2026-11-11', '2026-10-01', '2026-12-31'];
        yield 'primer trimestre'            => [Frequency::Quarterly, '2026-03-31', '2026-01-01', '2026-03-31'];
        yield 'primer semestre'             => [Frequency::Semiannual, '2026-06-30', '2026-01-01', '2026-06-30'];
        yield 'segundo semestre'            => [Frequency::Semiannual, '2026-07-01', '2026-07-01', '2026-12-31'];
        yield 'año'                         => [Frequency::Yearly, '2026-11-11', '2026-01-01', '2026-12-31'];
    }

    #[DataProvider('alignment')]
    public function testContainingAlineaAlPeriodoDeCalendario(Frequency $frequency, string $date, string $start, string $end): void
    {
        $period = BudgetPeriod::containing($frequency, self::day($date));

        $this->assertSame($start, $period->start->format('Y-m-d'));
        $this->assertSame($end, $period->end->format('Y-m-d'));
        $this->assertSame($start, $period->key());
    }

    public function testLaFrecuenciaDiariaNoValeParaPresupuestos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BudgetPeriod::containing(Frequency::Daily, self::day('2026-11-11'));
    }

    public function testNavegacionEntrePeriodos(): void
    {
        $december = BudgetPeriod::containing(Frequency::Monthly, self::day('2026-12-05'));

        $this->assertSame('2027-01-01', $december->next()->key());
        $this->assertSame('2026-11-01', $december->previous()->key());
        $this->assertSame('2026-06-01', $december->offset(-6)->key());

        $q4 = BudgetPeriod::containing(Frequency::Quarterly, self::day('2026-11-11'));
        $this->assertSame('2027-01-01', $q4->next()->key());
        $this->assertSame('2027-03-31', $q4->next()->end->format('Y-m-d'));

        $week = BudgetPeriod::containing(Frequency::Weekly, self::day('2026-12-30'));
        $this->assertSame('2027-01-04', $week->next()->key());
    }

    public function testCompararYContener(): void
    {
        $november = BudgetPeriod::containing(Frequency::Monthly, self::day('2026-11-11'));
        $december = $november->next();

        $this->assertLessThan(0, $november->compare($december));
        $this->assertSame(0, $november->compare(BudgetPeriod::containing(Frequency::Monthly, self::day('2026-11-30'))));
        $this->assertTrue($november->equals(BudgetPeriod::containing(Frequency::Monthly, self::day('2026-11-01'))));
        $this->assertTrue($november->contains(self::day('2026-11-30')));
        $this->assertFalse($november->contains(self::day('2026-12-01')));
    }

    public function testDiasTranscurridosYRestantes(): void
    {
        $november = BudgetPeriod::containing(Frequency::Monthly, self::day('2026-11-11'));
        $today = self::day('2026-11-11');

        $this->assertSame(30, $november->days());
        $this->assertSame(11, $november->elapsedDays($today));
        $this->assertSame(20, $november->daysLeft($today));
        $this->assertEqualsWithDelta(11 / 30, $november->elapsedFraction($today), 0.0001);

        $this->assertFalse($november->isClosed($today));
        $this->assertFalse($november->isFuture($today));
        $this->assertTrue($november->previous()->isClosed($today));
        $this->assertTrue($november->next()->isFuture($today));

        // Fuera del periodo: o no ha empezado o ya está entero.
        $this->assertSame(0, $november->next()->elapsedDays($today));
        $this->assertSame(31, $november->next()->daysLeft($today));
        $this->assertSame(31, $november->previous()->elapsedDays($today));
    }

    public function testOverlappingYStartingWithin(): void
    {
        $from = self::day('2026-10-01');
        $to = self::day('2026-10-31');

        // Tocan octubre 5 semanas: la del 28 sep también…
        $overlapping = BudgetPeriod::overlapping(Frequency::Weekly, $from, $to);
        $this->assertSame(['2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'],
            array_map(fn (BudgetPeriod $p) => $p->key(), $overlapping));

        // …pero solo 4 son de octubre: las que empiezan en él.
        $starting = BudgetPeriod::startingWithin(Frequency::Weekly, $from, $to);
        $this->assertSame(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'],
            array_map(fn (BudgetPeriod $p) => $p->key(), $starting));

        $months = BudgetPeriod::overlapping(Frequency::Monthly, self::day('2026-01-01'), self::day('2026-12-31'));
        $this->assertCount(12, $months);
    }

    public function testEtiquetas(): void
    {
        $week = BudgetPeriod::containing(Frequency::Weekly, self::day('2026-11-11'));
        $this->assertSame('semana del 9 nov 2026', $week->label());
        $this->assertSame('9–15 nov', $week->rangeLabel());
        $this->assertSame('30 nov – 6 dic', BudgetPeriod::containing(Frequency::Weekly, self::day('2026-12-01'))->rangeLabel());

        $november = BudgetPeriod::containing(Frequency::Monthly, self::day('2026-11-11'));
        $this->assertSame('noviembre 2026', $november->label());
        $this->assertSame('nov 2026', $november->shortLabel());

        $this->assertSame('oct–dic 2026', BudgetPeriod::containing(Frequency::Quarterly, self::day('2026-11-11'))->label());
        $this->assertSame('ene–jun 2026', BudgetPeriod::containing(Frequency::Semiannual, self::day('2026-02-01'))->label());
        $this->assertSame('2026', BudgetPeriod::containing(Frequency::Yearly, self::day('2026-02-01'))->label());

        $this->assertSame('la semana del 9 nov 2026', $week->labelWithArticle());
        $this->assertSame('noviembre 2026', $november->labelWithArticle());

        $this->assertSame('sem. del 9 nov', $week->labelWithoutYear());
        $this->assertSame('nov', $november->labelWithoutYear());
        $this->assertSame('oct–dic', BudgetPeriod::containing(Frequency::Quarterly, self::day('2026-11-11'))->labelWithoutYear());
    }
}
