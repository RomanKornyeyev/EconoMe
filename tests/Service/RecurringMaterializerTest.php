<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\RecurringTransaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Service\RecurringMaterializer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

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

    private function recurring(string $frequency, int $day, string $start, ?string $end = null): RecurringTransaction
    {
        $rec = new RecurringTransaction(
            $this->createStub(Account::class),
            $this->createStub(User::class),
        );
        $rec->setFrequency($frequency);
        $rec->setDayOfExecution($day);
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
        // Ejemplo de referencia: día 1, mensual, desde 01/05 → 3 ocurrencias a 11/07
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, 1, '2026-05-01');

        $this->assertSame(
            ['2026-05-01', '2026-06-01', '2026-07-01'],
            $this->occurrences($rec, '2026-05-01', '2026-07-11'),
        );
    }

    public function testMonthlyStartDateAfterAnchorSkipsToNextMonth(): void
    {
        // startDate 05/05 con día ancla 1 → la primera ocurrencia es 01/06
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, 1, '2026-05-05');

        $this->assertSame(
            ['2026-06-01', '2026-07-01'],
            $this->occurrences($rec, '2026-01-01', '2026-07-11'),
        );
    }

    public function testMonthlyDay31ClampsToLastDayOfMonth(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, 31, '2026-01-01');

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            $this->occurrences($rec, '2026-01-01', '2026-04-30'),
        );
    }

    public function testMonthlyDay29OnLeapFebruary(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, 29, '2028-01-01');

        $this->assertSame(
            ['2028-01-29', '2028-02-29'],
            $this->occurrences($rec, '2028-01-01', '2028-03-01'),
        );
    }

    // ── Semanal ──────────────────────────────────────────────────────────────

    public function testWeeklyAnchorsToIsoWeekday(): void
    {
        // 2026-07-01 es miércoles; día 1 (lunes) → primer lunes 2026-07-06
        $rec = $this->recurring(RecurringTransaction::FREQ_WEEKLY, 1, '2026-07-01');

        $this->assertSame(
            ['2026-07-06', '2026-07-13', '2026-07-20'],
            $this->occurrences($rec, '2026-07-01', '2026-07-20'),
        );
    }

    public function testWeeklyStartingOnAnchorDayIncludesIt(): void
    {
        // 2026-07-06 es lunes
        $rec = $this->recurring(RecurringTransaction::FREQ_WEEKLY, 1, '2026-07-06');

        $this->assertSame(
            ['2026-07-06', '2026-07-13'],
            $this->occurrences($rec, '2026-07-01', '2026-07-15'),
        );
    }

    public function testWeeklyWithInvalidDayReturnsNothing(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_WEEKLY, 15, '2026-07-01');

        $this->assertSame([], $this->occurrences($rec, '2026-07-01', '2026-08-01'));
    }

    // ── Diaria ───────────────────────────────────────────────────────────────

    public function testDailyGeneratesEveryDay(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_DAILY, 1, '2026-07-01');

        $this->assertSame(
            ['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05'],
            $this->occurrences($rec, '2026-07-01', '2026-07-05'),
        );
    }

    // ── Anual ────────────────────────────────────────────────────────────────

    public function testYearlyUsesStartDateMonthAsAnchor(): void
    {
        // Mes ancla = marzo (de startDate), día = 20
        $rec = $this->recurring(RecurringTransaction::FREQ_YEARLY, 20, '2026-03-15');

        $this->assertSame(
            ['2026-03-20', '2027-03-20'],
            $this->occurrences($rec, '2026-01-01', '2027-12-31'),
        );
    }

    public function testYearlyAnchorBeforeStartDateSkipsToNextYear(): void
    {
        // Día 10 de marzo < startDate 15/03 → primera ocurrencia el año siguiente
        $rec = $this->recurring(RecurringTransaction::FREQ_YEARLY, 10, '2026-03-15');

        $this->assertSame(
            ['2027-03-10'],
            $this->occurrences($rec, '2026-01-01', '2027-12-31'),
        );
    }

    // ── Límites ──────────────────────────────────────────────────────────────

    public function testEndDateClipsOccurrences(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, 1, '2026-05-01', '2026-06-15');

        $this->assertSame(
            ['2026-05-01', '2026-06-01'],
            $this->occurrences($rec, '2026-01-01', '2026-12-31'),
        );
    }

    public function testWindowBeforeStartDateIsEmpty(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, 1, '2026-05-01');

        $this->assertSame([], $this->occurrences($rec, '2026-01-01', '2026-04-30'));
    }

    public function testWindowNarrowsToSingleOccurrence(): void
    {
        $rec = $this->recurring(RecurringTransaction::FREQ_MONTHLY, 1, '2026-01-01');

        $this->assertSame(
            ['2026-06-01'],
            $this->occurrences($rec, '2026-05-15', '2026-06-15'),
        );
    }
}
