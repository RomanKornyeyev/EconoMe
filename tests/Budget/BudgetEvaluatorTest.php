<?php

namespace App\Tests\Budget;

use App\Budget\AmountSchedule;
use App\Budget\BudgetEvaluator;
use App\Budget\BudgetOutcome;
use App\Budget\BudgetPeriod;
use App\Budget\BudgetRow;
use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\Frequency;
use App\Repository\TransactionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Evaluación de presupuestos con los datos del mockup (claude/presupuestos.md
 * §12). Hoy es el miércoles 11 nov 2026: día 11 de 30, semana del 9 nov.
 */
class BudgetEvaluatorTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private const ALIMENTACION = 1;
    private const RESTAURANTES = 2;
    private const OCIO = 3;
    private const SEGUROS = 4;

    private Account $account;
    private User $user;

    protected function setUp(): void
    {
        $this->account = $this->createStub(Account::class);
        $this->user = $this->createStub(User::class);
    }

    private function category(int $id, string $name): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);

        return $category;
    }

    /**
     * @param array<string, string> $steps
     */
    private function budget(string $type, Frequency $frequency, ?Category $category, string $start, array $steps, ?string $end = null): Budget
    {
        $first = BudgetPeriod::containing($frequency, new \DateTimeImmutable($start));
        $last = $end === null ? null : BudgetPeriod::containing($frequency, new \DateTimeImmutable($end));

        return new Budget($this->account, $this->user, $type, $frequency, $category, new AmountSchedule($first, $last, $steps));
    }

    /**
     * @param list<array{0: ?int, 1: string, 2: string, 3: string, 4?: string}> $rows [categoría, tipo, fecha, total, recurrente]
     */
    private function evaluator(array $rows): BudgetEvaluator
    {
        $repo = $this->createStub(TransactionRepository::class);
        $repo->method('dailyTotalsByCategory')->willReturn(array_map(fn (array $r) => [
            'categoryId' => $r[0],
            'type'       => $r[1],
            'date'       => $r[2],
            'total'      => $r[3],
            'recurring'  => $r[4] ?? '0.00',
        ], $rows));

        return new BudgetEvaluator($repo);
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    /** @param list<BudgetRow> $rows */
    private static function byName(array $rows, string $name): BudgetRow
    {
        foreach ($rows as $row) {
            if ($row->budget->getName() === $name) {
                return $row;
            }
        }
        self::fail("No hay fila para $name");
    }

    public function testMesEnCursoEstadosOrdenYKpi(): void
    {
        $budgets = [
            $this->budget('expense', Frequency::Monthly, $this->category(self::RESTAURANTES, 'Restaurantes'), '2026-01-01', ['2026-01-01' => '80']),
            $this->budget('expense', Frequency::Monthly, $this->category(self::ALIMENTACION, 'Alimentación'), '2026-01-01', ['2026-01-01' => '400', '2026-07-01' => '420']),
            $this->budget('expense', Frequency::Monthly, null, '2026-01-01', ['2026-01-01' => '1400']),
            $this->budget('expense', Frequency::Weekly, $this->category(self::OCIO, 'Ocio'), '2026-01-05', ['2026-01-05' => '40']),
            $this->budget('income', Frequency::Monthly, null, '2026-01-01', ['2026-01-01' => '1700']),
        ];
        $evaluator = $this->evaluator([
            [self::RESTAURANTES, 'expense', '2026-11-03', '72.40'],
            [self::ALIMENTACION, 'expense', '2026-11-05', '109.36'],
            [self::OCIO, 'expense', '2026-11-08', '11.70'],  // domingo: semana anterior
            [self::OCIO, 'expense', '2026-11-10', '46.50'],
            [null, 'expense', '2026-11-01', '679.00', '679.00'], // hipoteca recurrente, sin categoría
            [null, 'income', '2026-11-01', '1750.00', '1750.00'],
            [self::ALIMENTACION, 'income', '2026-11-06', '20.00'], // devolución: no descuenta
        ]);

        $panel = $evaluator->panel($this->account, $budgets, 2026, 11, self::today());

        $this->assertSame('current', $panel->tense);
        $this->assertSame('noviembre 2026', $panel->label);

        $ocio = $panel->rows[0];
        $this->assertSame('Ocio', $ocio->budget->getName(), 'lo pasado, arriba');
        $this->assertSame(BudgetOutcome::Over, $ocio->outcome);
        $this->assertSame('46.50', $ocio->spent, 'solo la semana del 9 nov');
        $this->assertSame(5, $ocio->daysLeft);
        $this->assertSame(86.0, $ocio->limitMark(), 'pasado: el límite (40) queda dentro de la barra de lo llevado (46,50)');

        $this->assertSame(BudgetOutcome::Near, $panel->rows[1]->outcome);
        $this->assertSame(91, $panel->rows[1]->percent());

        $alimentacion = self::byName($panel->rows, 'Alimentación');
        $this->assertSame(BudgetOutcome::OnTrack, $alimentacion->outcome);
        $this->assertSame('420.00', $alimentacion->limit, 'el límite del tramo vigente');
        $this->assertSame('109.36', $alimentacion->spent, 'la devolución (ingreso) no cuenta');
        $this->assertSame(20, $alimentacion->daysLeft);
        $this->assertSame('15.53', $alimentacion->perDay());
        $this->assertSame(37, $alimentacion->elapsed);
        $this->assertSame(100.0, $alimentacion->limitMark(), 'sin pasarse, la línea va al final de la barra');

        $total = self::byName($panel->rows, 'Gasto total');
        // 72,40 + 109,36 + 11,70 + 46,50 + 679: el domingo 8 es de la semana
        // anterior, pero sí del mes.
        $this->assertSame('918.96', $total->spent, 'toda la cuenta suma también lo que no tiene categoría');
        $this->assertSame('679.00', $total->recurring);
        // 66 % con el 37 % del mes, pero 679 son la hipoteca: lo variable va al 33 %.
        $this->assertSame(BudgetOutcome::OnTrack, $total->outcome, 'las recurrentes no hacen ir rápido');

        $ingresos = self::byName($panel->rows, 'Ingresos totales');
        $this->assertSame(BudgetOutcome::GoalReached, $ingresos->outcome);
        $this->assertSame(96.0, $ingresos->limitMark(), 'meta superada: 1700 de 1770 (nómina y devolución)');

        $this->assertSame(3, $panel->goodCount());
        $this->assertCount(2, $panel->atRisk());
    }

    /** Día 11 de 30: ha pasado el 37 % del mes, así que «rápido» empieza en el 57 %. */
    public function testVasRapidoPorDelanteDelRitmo(): void
    {
        $comida = $this->budget('expense', Frequency::Monthly, $this->category(self::ALIMENTACION, 'Alimentación'), '2026-01-01', ['2026-01-01' => '400']);

        $this->assertSame(BudgetOutcome::Fast, BudgetEvaluator::liveOutcome($comida, '228.00', '0.00', '400.00', 37), '57 %');
        $this->assertSame(BudgetOutcome::OnTrack, BudgetEvaluator::liveOutcome($comida, '224.00', '0.00', '400.00', 37), '56 %: aún dentro del margen');
        $this->assertSame(BudgetOutcome::Near, BudgetEvaluator::liveOutcome($comida, '320.00', '0.00', '400.00', 37), 'desde el 80 % manda «cerca»');
        $this->assertSame(BudgetOutcome::Fast, BudgetEvaluator::liveOutcome($comida, '20.00', '0.00', '100.00', 0), 'el primer día ya cuenta');

        // Con 300 de recurrentes sobre 1000, el ritmo se mide sobre los 700 libres.
        $total = $this->budget('expense', Frequency::Monthly, null, '2026-01-01', ['2026-01-01' => '1000']);
        $this->assertSame(BudgetOutcome::OnTrack, BudgetEvaluator::liveOutcome($total, '600.00', '300.00', '1000.00', 37), '60 % del total, pero 43 % de lo libre');
        $this->assertSame(BudgetOutcome::Fast, BudgetEvaluator::liveOutcome($total, '720.00', '300.00', '1000.00', 37), '60 % de lo libre');
        $this->assertSame(BudgetOutcome::Near, BudgetEvaluator::liveOutcome($total, '900.00', '900.00', '1000.00', 37), 'recurrentes que llenan casi todo: cerca');

        $ingresos = $this->budget('income', Frequency::Monthly, null, '2026-01-01', ['2026-01-01' => '1700']);
        $this->assertSame(BudgetOutcome::GoalPending, BudgetEvaluator::liveOutcome($ingresos, '1600.00', '0.00', '1700.00', 3), 'las metas no van «rápido»');
    }

    public function testVasRapidoEnElPanelYEnCursoDesdeUnMesCerrado(): void
    {
        $budgets = [
            $this->budget('expense', Frequency::Monthly, $this->category(self::ALIMENTACION, 'Alimentación'), '2026-01-01', ['2026-01-01' => '420']),
            $this->budget('expense', Frequency::Quarterly, $this->category(self::SEGUROS, 'Seguros'), '2026-01-01', ['2026-01-01' => '350']),
        ];
        $evaluator = $this->evaluator([
            [self::ALIMENTACION, 'expense', '2026-11-05', '250.00'], // 60 % el día 11
            [self::SEGUROS, 'expense', '2026-10-01', '250.00'],      // 71 % con el 46 % del trimestre
        ]);

        $panel = $evaluator->panel($this->account, $budgets, 2026, 11, self::today());
        $alimentacion = self::byName($panel->rows, 'Alimentación');
        $this->assertSame(BudgetOutcome::Fast, $alimentacion->outcome);
        $this->assertSame([60, 37], [$alimentacion->percent(), $alimentacion->elapsed]);
        $this->assertSame(BudgetOutcome::Fast, self::byName($panel->rows, 'Seguros')->outcome);
        $this->assertSame(0, $panel->goodCount());
        $this->assertCount(2, $panel->atRisk(), 'ir rápido es un aviso, como «cerca»');

        // Visto desde octubre (cerrado), el trimestre sigue abierto: solo «en curso».
        $october = $evaluator->panel($this->account, $budgets, 2026, 10, self::today());
        $this->assertSame(BudgetOutcome::Running, self::byName($october->rows, 'Seguros')->outcome);
    }

    public function testMesCerradoSemanasYTrimestreAunAbierto(): void
    {
        $budgets = [
            $this->budget('expense', Frequency::Monthly, $this->category(self::ALIMENTACION, 'Alimentación'), '2026-01-01', ['2026-01-01' => '400', '2026-07-01' => '420']),
            $this->budget('expense', Frequency::Weekly, $this->category(self::OCIO, 'Ocio'), '2026-01-05', ['2026-01-05' => '40']),
            $this->budget('expense', Frequency::Quarterly, $this->category(self::SEGUROS, 'Seguros'), '2026-01-01', ['2026-01-01' => '350']),
        ];
        $evaluator = $this->evaluator([
            [self::ALIMENTACION, 'expense', '2026-10-20', '431.20'],
            [self::OCIO, 'expense', '2026-10-05', '30.00'],
            [self::OCIO, 'expense', '2026-10-13', '55.00'], // se pasa la semana del 12
            [self::OCIO, 'expense', '2026-10-20', '20.00'],
            [self::OCIO, 'expense', '2026-10-27', '40.00'],
            [self::SEGUROS, 'expense', '2026-10-01', '99.38'],
            [self::SEGUROS, 'expense', '2026-11-01', '99.38'],
        ]);

        $panel = $evaluator->panel($this->account, $budgets, 2026, 10, self::today());

        $this->assertSame('past', $panel->tense);

        $alimentacion = self::byName($panel->rows, 'Alimentación');
        $this->assertSame(BudgetOutcome::Exceeded, $alimentacion->outcome, 'octubre se juzga contra su límite: 420');
        $this->assertSame('-11.20', $alimentacion->remaining());

        $ocio = self::byName($panel->rows, 'Ocio');
        $this->assertTrue($ocio->summary);
        $this->assertSame(BudgetOutcome::PartlyMet, $ocio->outcome);
        $this->assertSame([3, 4], [$ocio->met, $ocio->closed], 'las 4 semanas que empiezan en octubre');
        $this->assertSame('2026-10-12', $ocio->failed[0]->key());

        $seguros = self::byName($panel->rows, 'Seguros');
        $this->assertSame(BudgetOutcome::Running, $seguros->outcome, 'el trimestre sigue abierto');
        $this->assertSame('198.76', $seguros->spent);

        // Seguros, aún abierto, no cuenta para «X de Y cumplidos».
        $this->assertCount(2, $panel->evaluated());
        $this->assertSame(0, $panel->successCount());
        $this->assertSame(1, $panel->runningCount());
    }

    public function testVistaDeAnoResumeLosPeriodosCerradosConSuLimite(): void
    {
        $alimentacion = $this->budget('expense', Frequency::Monthly, $this->category(self::ALIMENTACION, 'Alimentación'),
            '2026-01-01', ['2026-01-01' => '400', '2026-07-01' => '420']);
        $spent = [388.2, 412.5, 365.4, 391, 436.8, 398.7, 405.1, 417.9, 402.3, 431.2];
        $rows = [];
        foreach ($spent as $i => $value) {
            $rows[] = [self::ALIMENTACION, 'expense', sprintf('2026-%02d-10', $i + 1), number_format($value, 2, '.', '')];
        }

        $panel = $this->evaluator($rows)->panel($this->account, [$alimentacion], 2026, 0, self::today());
        $row = $panel->rows[0];

        $this->assertTrue($panel->yearView);
        $this->assertTrue($row->summary);
        // Jul–sep se pasaron de 400 pero no de 420: se juzgan con su límite.
        $this->assertSame([7, 10], [$row->met, $row->closed]);
        $this->assertSame(['2026-02-01', '2026-05-01', '2026-10-01'], array_map(fn ($p) => $p->key(), $row->failed));
        $this->assertSame(BudgetOutcome::PartlyMet, $row->outcome);
        $this->assertSame(70, $panel->periodsPercent());
    }

    public function testMesFuturoPendienteConImportePuntual(): void
    {
        $restaurantes = $this->budget('expense', Frequency::Monthly, $this->category(self::RESTAURANTES, 'Restaurantes'),
            '2026-01-01', ['2026-01-01' => '80', '2026-12-01' => '150', '2027-01-01' => '80']);

        $panel = $this->evaluator([])->panel($this->account, [$restaurantes], 2026, 12, self::today());
        $row = $panel->rows[0];

        $this->assertSame('future', $panel->tense);
        $this->assertSame(BudgetOutcome::Pending, $row->outcome);
        $this->assertSame('150.00', $row->limit);
        $this->assertSame('80.00', $row->usualAmount);
    }

    public function testFueraDeVigenciaNoSaleEnElPanel(): void
    {
        $upcoming = $this->budget('expense', Frequency::Monthly, null, '2026-12-01', ['2026-12-01' => '300']);
        $ended = $this->budget('expense', Frequency::Monthly, $this->category(self::OCIO, 'Ocio'), '2026-01-01', ['2026-01-01' => '50'], '2026-09-30');

        $panel = $this->evaluator([])->panel($this->account, [$upcoming, $ended], 2026, 11, self::today());

        $this->assertTrue($panel->isEmpty());
        $this->assertSame(2, $panel->budgetCount);
    }

    public function testCurrentDeLaPaginaYHistorialCerrado(): void
    {
        $alimentacion = $this->budget('expense', Frequency::Monthly, $this->category(self::ALIMENTACION, 'Alimentación'),
            '2026-09-01', ['2026-09-01' => '400']);
        $evaluator = $this->evaluator([
            [self::ALIMENTACION, 'expense', '2026-09-10', '380.00'],
            [self::ALIMENTACION, 'expense', '2026-10-10', '431.20'],
            [self::ALIMENTACION, 'expense', '2026-11-10', '100.00'],
        ]);

        $history = $evaluator->closedHistory($alimentacion, self::today());
        $this->assertSame(['2026-09-01', '2026-10-01'], array_map(fn ($h) => $h['period']->key(), $history));
        $this->assertSame(['380.00', '431.20'], array_column($history, 'spent'));

        [$spent] = $evaluator->spentIn($alimentacion, $alimentacion->periodAt(self::today()));
        $this->assertSame('100.00', $spent);
    }
}
