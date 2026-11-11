<?php

namespace App\Tests\Budget;

use App\Budget\AmountSchedule;
use App\Budget\BudgetPeriod;
use App\Enum\Frequency;
use PHPUnit\Framework\TestCase;

/**
 * Tramos de importe: los tres alcances de edición, quitar cambios, finalizar y
 * las invariantes (claude/presupuestos.md §4.4 y §8).
 */
class AmountScheduleTest extends TestCase
{
    private static function month(string $date): BudgetPeriod
    {
        return BudgetPeriod::containing(Frequency::Monthly, new \DateTimeImmutable($date));
    }

    /** Restaurantes del mockup: 80 € desde ene 2026 y 150 € solo en dic 2026. */
    private static function restaurantes(): AmountSchedule
    {
        return new AmountSchedule(self::month('2026-01-01'), null, [
            '2026-01-01' => '80',
            '2026-12-01' => '150',
            '2027-01-01' => '80',
        ]);
    }

    /** Alimentación del mockup: 400 € desde ene 2026 y 420 € desde jul 2026. */
    private static function alimentacion(): AmountSchedule
    {
        return new AmountSchedule(self::month('2026-01-01'), null, [
            '2026-01-01' => '400',
            '2026-07-01' => '420',
        ]);
    }

    public function testElLimiteDeUnPeriodoEsElDelUltimoTramoQueEmpiezaAntes(): void
    {
        $schedule = self::alimentacion();

        $this->assertSame('400.00', $schedule->amountAt(self::month('2026-06-15')));
        $this->assertSame('420.00', $schedule->amountAt(self::month('2026-07-01')));
        $this->assertSame('420.00', $schedule->amountAt(self::month('2030-01-01')));
        $this->assertNull($schedule->amountAt(self::month('2025-12-01')), 'antes del inicio no hay límite');
    }

    public function testSoloUnPeriodoAnadeElTramoDeVuelta(): void
    {
        $schedule = self::alimentacion()->applyOnce(self::month('2026-12-01'), '600');

        $this->assertSame([
            '2026-01-01' => '400.00',
            '2026-07-01' => '420.00',
            '2026-12-01' => '600.00',
            '2027-01-01' => '420.00',
        ], $schedule->steps());
    }

    public function testSoloUnPeriodoSobreOtroPuntualLoSustituyeYConservaLaVuelta(): void
    {
        $schedule = self::restaurantes()->applyOnce(self::month('2026-12-01'), '200');

        $this->assertSame([
            '2026-01-01' => '80.00',
            '2026-12-01' => '200.00',
            '2027-01-01' => '80.00',
        ], $schedule->steps());
    }

    public function testSoloUnPeriodoEnElUltimoDeLaVigenciaNoAnadeVuelta(): void
    {
        $schedule = AmountSchedule::initial(self::month('2026-01-01'), self::month('2026-12-01'), '80')
            ->applyOnce(self::month('2026-12-01'), '150');

        $this->assertSame(['2026-01-01' => '80.00', '2026-12-01' => '150.00'], $schedule->steps());
    }

    public function testDesdeUnPeriodoDescartaLosTramosPosteriores(): void
    {
        $schedule = self::restaurantes()->applyFrom(self::month('2026-11-01'), '100');

        $this->assertSame(['2026-01-01' => '80.00', '2026-11-01' => '100.00'], $schedule->steps());
    }

    public function testDesdeConElMismoImporteYSinTramosPosterioresNoCambiaNada(): void
    {
        $schedule = self::alimentacion();

        $this->assertTrue($schedule->applyFrom(self::month('2026-11-01'), '420')->equals($schedule));
    }

    public function testTodoElHistorialDejaUnUnicoTramo(): void
    {
        $schedule = self::restaurantes()->applyAll('90');

        $this->assertSame(['2026-01-01' => '90.00'], $schedule->steps());
    }

    public function testSeFusionanTramosSeguidosConElMismoImporte(): void
    {
        $schedule = new AmountSchedule(self::month('2026-01-01'), null, [
            '2026-01-01' => '80',
            '2026-03-01' => '80.00',
            '2026-05-01' => '90',
        ]);

        $this->assertSame(['2026-01-01' => '80.00', '2026-05-01' => '90.00'], $schedule->steps());
    }

    public function testCambiosDistingueRangosYPuntuales(): void
    {
        $schedule = new AmountSchedule(self::month('2026-01-01'), null, [
            '2026-01-01' => '400',
            '2026-07-01' => '420',
            '2026-12-01' => '600',
            '2027-01-01' => '420',
        ]);
        $current = self::month('2026-11-11');

        $changes = $schedule->changes();
        $this->assertSame(['Ene 2026 – jun 2026', 'Desde jul 2026', 'Solo dic 2026'], array_map(fn ($c) => $c->label(), $changes));
        $this->assertSame(['past', 'current', 'upcoming'], array_map(fn ($c) => $c->status($current), $changes));
        $this->assertSame([false, false, true], array_map(fn ($c) => $c->isRemovable($current), $changes));
        $this->assertTrue($changes[2]->once);
        $this->assertSame('420.00', $changes[2]->baseAmount);
    }

    public function testQuitarUnPuntualBorraSusDosTramos(): void
    {
        $schedule = self::restaurantes();
        $once = $schedule->changeStartingAt('2026-12-01');

        $this->assertNotNull($once);
        $this->assertSame(['2026-01-01' => '80.00'], $schedule->without($once)->steps());
    }

    public function testQuitarUnRangoDevuelveSusPuntualesAlImporteAnterior(): void
    {
        // 80 → 100 desde jul, con 150 solo en dic (que vuelve a 100).
        $schedule = new AmountSchedule(self::month('2026-01-01'), null, [
            '2026-01-01' => '80',
            '2026-07-01' => '100',
            '2026-12-01' => '150',
            '2027-01-01' => '100',
        ]);

        $result = $schedule->without($schedule->changeStartingAt('2026-07-01'));

        // Sin el rango de julio, diciembre sigue siendo especial pero vuelve a 80.
        $this->assertSame(['2026-01-01' => '80.00', '2026-12-01' => '150.00', '2027-01-01' => '80.00'], $result->steps());
        $this->assertTrue($result->changeStartingAt('2026-12-01')->once);
    }

    public function testElPrimerRangoNoSePuedeQuitar(): void
    {
        $schedule = self::alimentacion();

        $this->expectException(\InvalidArgumentException::class);
        $schedule->without($schedule->changes()[0]);
    }

    public function testFinalizarDescartaLosTramosPosteriores(): void
    {
        $schedule = self::restaurantes()->endingAt(self::month('2026-11-01'));

        $this->assertSame(['2026-01-01' => '80.00'], $schedule->steps());
        $this->assertNull($schedule->amountAt(self::month('2026-12-01')), 'después del fin no hay límite');
    }

    public function testCambioPuntualYProximoCambio(): void
    {
        $schedule = self::restaurantes();

        $this->assertNotNull($schedule->onceChangeAt(self::month('2026-12-01')));
        $this->assertNull($schedule->onceChangeAt(self::month('2026-11-01')));
        $this->assertSame('2026-12-01', $schedule->upcomingChange(self::month('2026-11-01'))->from->key());
        $this->assertNull($schedule->upcomingChange(self::month('2026-12-01')));
    }

    public function testSemanalCruzandoDeAno(): void
    {
        $week = BudgetPeriod::containing(Frequency::Weekly, new \DateTimeImmutable('2025-12-29'));
        $schedule = AmountSchedule::initial($week->offset(-4), null, '40')->applyOnce($week, '60');

        $this->assertSame([
            $week->offset(-4)->key() => '40.00',
            '2025-12-29' => '60.00',
            '2026-01-05' => '40.00',
        ], $schedule->steps());
    }

    public function testRechazaTramosQueNoEmpiezanEnUnPeriodo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AmountSchedule(self::month('2026-01-01'), null, ['2026-01-01' => '80', '2026-02-15' => '90']);
    }

    public function testRechazaImportesNoPositivos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AmountSchedule::initial(self::month('2026-01-01'), null, '0');
    }

    public function testRechazaEditarFueraDeLaVigencia(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::alimentacion()->applyFrom(self::month('2025-06-01'), '300');
    }
}
