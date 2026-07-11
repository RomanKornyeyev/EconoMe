<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\RecurringTransaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Service\RecurringMaterializer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Semántica (lógica bancaria): startDate es la fecha del PRIMER movimiento
 * y ancla todo el calendario. Semanal = +7 días; mensual = mismo día de cada
 * mes; anual = mismo día y mes de cada año. Días 29-31 en meses cortos se
 * ajustan al último día del mes.
 */
class RecurringMaterializerTest extends TestCase
{
    private RecurringMaterializer $materializer;

    protected function setUp(): void
    {
        $this->materializer = new RecurringMaterializer(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(TransactionRepository::class),
        );
    }

    private function recurring(string $frequency, string $start, ?string $end = null): RecurringTransaction
    {
        $rec = new RecurringTransaction(
            $this->createStub(Account::class),
            $this->createStub(User::class),
        );
        $rec->setFrequency($frequency);
        $rec->setStartDate(new \DateTimeImmutable($start));
        if ($end !== null) {
            $rec->setEndDate(new \DateTimeImmutable($end));
        }

        return $rec;
    }

    /** @return string[] */
    private function occurrences(RecurringTransaction $rec, string $from, string $to): array
    {
        return array_map(
            fn (\DateTimeImmutable $d) => $d->format('Y-m-d'),
            $this->materializer->occurrencesBetween($rec, new \DateTimeImmutable($from), new \DateTimeImmutable($to)),
        );
    }

    // ── Mensual ──────────────────────────────────────────────────────────────

    public function testMonthlyBasicBackfill(): void
    {
        // Ejemplo de referencia: mensual desde 01/05 → 3 ocurrencias a 11/07
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, '2026-05-01');

        $this->assertSame(
            ['2026-05-01', '2026-06-01', '2026-07-01'],
            $this->occurrences($rec, '2026-05-01', '2026-07-11'),
        );
    }

    public function testMonthlyFirstOccurrenceIsStartDateItself(): void
    {
        // startDate ES el primer movimiento; el día del mes se hereda de ella
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, '2026-05-05');

        $this->assertSame(
            ['2026-05-05', '2026-06-05', '2026-07-05'],
            $this->occurrences($rec, '2026-01-01', '2026-07-11'),
        );
    }

    public function testMonthlyDay31ClampsToLastDayOfMonth(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, '2026-01-31');

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            $this->occurrences($rec, '2026-01-01', '2026-04-30'),
        );
    }

    public function testMonthlyDay29OnLeapFebruary(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, '2028-01-29');

        $this->assertSame(
            ['2028-01-29', '2028-02-29'],
            $this->occurrences($rec, '2028-01-01', '2028-03-01'),
        );
    }

    // ── Semanal ──────────────────────────────────────────────────────────────

    public function testWeeklyRepeatsEverySevenDaysFromStartDate(): void
    {
        // 2026-07-05 es domingo → serie de domingos: 5, 12, 19, 26, 2/08…
        $rec = $this->recurring(RecurringTransaction::FREQ_WEEKLY, '2026-07-05');

        $this->assertSame(
            ['2026-07-05', '2026-07-12', '2026-07-19', '2026-07-26', '2026-08-02'],
            $this->occurrences($rec, '2026-07-01', '2026-08-05'),
        );
    }

    public function testWeeklyWindowStartsMidSeries(): void
    {
        // Materializar desde mitad de la serie: primer elemento >= from
        $rec = $this->recurring(RecurringTransaction::FREQ_WEEKLY, '2026-07-05');

        $this->assertSame(
            ['2026-07-12', '2026-07-19'],
            $this->occurrences($rec, '2026-07-10', '2026-07-20'),
        );
    }

    public function testWeeklyKeepsWeekdayAcrossMonths(): void
    {
        // 2026-07-31 es viernes; la serie cruza de mes manteniendo el viernes
        $rec = $this->recurring(RecurringTransaction::FREQ_WEEKLY, '2026-07-31');

        $this->assertSame(
            ['2026-07-31', '2026-08-07', '2026-08-14'],
            $this->occurrences($rec, '2026-07-01', '2026-08-15'),
        );
    }

    // ── Diaria ───────────────────────────────────────────────────────────────

    public function testDailyGeneratesEveryDay(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_DAILY, '2026-07-01');

        $this->assertSame(
            ['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05'],
            $this->occurrences($rec, '2026-07-01', '2026-07-05'),
        );
    }

    // ── Anual ────────────────────────────────────────────────────────────────

    public function testYearlyRepeatsOnStartDateDayAndMonth(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_YEARLY, '2026-03-15');

        $this->assertSame(
            ['2026-03-15', '2027-03-15'],
            $this->occurrences($rec, '2026-01-01', '2027-12-31'),
        );
    }

    public function testYearlyLeapDayClampsOnNonLeapYears(): void
    {
        // 29/02 en año no bisiesto → 28/02
        $rec = $this->recurring(RecurringTransaction::FREQ_YEARLY, '2028-02-29');

        $this->assertSame(
            ['2028-02-29', '2029-02-28'],
            $this->occurrences($rec, '2028-01-01', '2029-12-31'),
        );
    }

    // ── Límites ──────────────────────────────────────────────────────────────

    public function testEndDateClipsOccurrences(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, '2026-05-01', '2026-06-15');

        $this->assertSame(
            ['2026-05-01', '2026-06-01'],
            $this->occurrences($rec, '2026-01-01', '2026-12-31'),
        );
    }

    public function testWindowBeforeStartDateIsEmpty(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, '2026-05-01');

        $this->assertSame([], $this->occurrences($rec, '2026-01-01', '2026-04-30'));
    }

    public function testWindowNarrowsToSingleOccurrence(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, '2026-01-01');

        $this->assertSame(
            ['2026-06-01'],
            $this->occurrences($rec, '2026-05-15', '2026-06-15'),
        );
    }
}
