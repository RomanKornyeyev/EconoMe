<?php

namespace App\Service;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Materializa transacciones normales a partir de recurrentes.
 *
 * Reglas de diseño:
 *  - Solo se materializa hasta HOY: las ocurrencias futuras las creará el
 *    comando programado cuando llegue su fecha.
 *  - startDate es la fecha del PRIMER movimiento efectivo y ancla todo el
 *    calendario (lógica bancaria): semanal = cada 7 días desde startDate;
 *    mensual = el día de startDate cada mes; anual = el día y mes de startDate
 *    cada año. endDate es solo límite superior.
 *  - Días 29-31 en meses que no los tienen se ajustan al último día del mes.
 *  - lastGeneratedAt actúa como CURSOR de generación: el comando solo crea
 *    ocurrencias desde el cursor, de modo que los periodos en pausa no se
 *    rellenan retroactivamente al reactivar.
 *  - La deduplicación es por (recurringSource, date): re-ejecutar cualquier
 *    método es idempotente.
 */
class RecurringMaterializer
{
    /** Tope de seguridad de inserts por operación (p. ej. diaria con startDate muy antigua). */
    public const MAX_BACKFILL = 1000;

    public function __construct(
        private EntityManagerInterface $em,
        private TransactionRepository $transactionRepo,
    ) {}

    /**
     * Fechas de ocurrencia del calendario dentro de [from, to], ya recortadas
     * a los límites [startDate, endDate] de la recurrente.
     *
     * Cálculo puro: no toca base de datos.
     *
     * @return \DateTimeImmutable[]
     */
    public function occurrencesBetween(RecurringTransaction $rec, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $from = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $to   = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);

        $start = \DateTimeImmutable::createFromInterface($rec->getStartDate())->setTime(0, 0);
        if ($from < $start) {
            $from = $start;
        }
        if ($rec->getEndDate() !== null) {
            $end = \DateTimeImmutable::createFromInterface($rec->getEndDate())->setTime(0, 0);
            if ($to > $end) {
                $to = $end;
            }
        }
        if ($from > $to) {
            return [];
        }

        return match ($rec->getFrequency()) {
            RecurringTransaction::FREQ_DAILY   => $this->dailyOccurrences($from, $to),
            RecurringTransaction::FREQ_WEEKLY  => $this->weeklyOccurrences($from, $to, $start),
            RecurringTransaction::FREQ_MONTHLY => $this->monthlyOccurrences($from, $to, (int) $start->format('j')),
            RecurringTransaction::FREQ_YEARLY  => $this->yearlyOccurrences($from, $to, (int) $start->format('j'), (int) $start->format('n')),
            default => [],
        };
    }

    /**
     * Crea las transacciones que falten en [$from ?? startDate, hoy] y avanza el cursor.
     * No borra nada. No hace flush (responsabilidad del llamador).
     *
     * Usado por el create del controlador (backfill completo) y por el comando
     * programado (ventana desde el cursor).
     *
     * @return int Número de transacciones creadas (persistidas, sin flush)
     */
    public function generateMissing(RecurringTransaction $rec, ?\DateTimeInterface $from = null): int
    {
        $today = new \DateTimeImmutable('today');
        $expected = $this->occurrencesBetween($rec, $from ?? $rec->getStartDate(), $today);

        if (\count($expected) > self::MAX_BACKFILL) {
            throw new \DomainException(sprintf(
                'La configuración generaría %d movimientos (máximo %d). Ajusta la fecha de inicio o la frecuencia.',
                \count($expected),
                self::MAX_BACKFILL,
            ));
        }

        $existing = $this->existingDates($rec);
        $created = 0;

        foreach ($expected as $date) {
            if (isset($existing[$date->format('Y-m-d')])) {
                continue;
            }
            $this->em->persist($this->newTransactionFor($rec, $date));
            $created++;
        }

        $rec->setLastGeneratedAt($today);

        return $created;
    }

    /**
     * Plan de reconciliación completa del calendario en [startDate, hoy]:
     * qué fechas faltan por crear y qué transacciones generadas sobran
     * (fecha fuera del calendario actual o duplicada).
     *
     * No modifica nada: sirve para mostrar el impacto al usuario antes de confirmar.
     *
     * @return array{create: \DateTimeImmutable[], delete: Transaction[]}
     */
    public function computeCalendarSyncPlan(RecurringTransaction $rec): array
    {
        $today = new \DateTimeImmutable('today');
        $expected = $this->occurrencesBetween($rec, $rec->getStartDate(), $today);
        $expectedKeys = array_fill_keys(array_map(fn ($d) => $d->format('Y-m-d'), $expected), true);

        $seen = [];
        $toDelete = [];
        foreach ($this->transactionRepo->findByRecurringSource($rec) as $tx) {
            $key = $tx->getDate()->format('Y-m-d');
            if (!isset($expectedKeys[$key]) || isset($seen[$key])) {
                $toDelete[] = $tx;
            } else {
                $seen[$key] = true;
            }
        }

        $toCreate = [];
        foreach ($expected as $date) {
            if (!isset($seen[$date->format('Y-m-d')])) {
                $toCreate[] = $date;
            }
        }

        if (\count($toCreate) > self::MAX_BACKFILL) {
            throw new \DomainException(sprintf(
                'La configuración generaría %d movimientos (máximo %d). Ajusta la fecha de inicio o la frecuencia.',
                \count($toCreate),
                self::MAX_BACKFILL,
            ));
        }

        return ['create' => $toCreate, 'delete' => $toDelete];
    }

    /**
     * Aplica un plan calculado con computeCalendarSyncPlan y avanza el cursor.
     * No hace flush.
     *
     * @param array{create: \DateTimeImmutable[], delete: Transaction[]} $plan
     * @return array{created: int, deleted: int}
     */
    public function applyCalendarSyncPlan(RecurringTransaction $rec, array $plan): array
    {
        foreach ($plan['delete'] as $tx) {
            $this->em->remove($tx);
        }
        foreach ($plan['create'] as $date) {
            $this->em->persist($this->newTransactionFor($rec, $date));
        }

        $rec->setLastGeneratedAt(new \DateTimeImmutable('today'));

        return ['created' => \count($plan['create']), 'deleted' => \count($plan['delete'])];
    }

    /**
     * Propaga los campos de valor (nombre, descripción, importe, tipo, categoría)
     * de la recurrente a todas sus transacciones ya generadas.
     *
     * Ojo: sobrescribe ediciones manuales de esos campos. No hace flush.
     *
     * @return int Número de transacciones actualizadas
     */
    public function applyValuesToGenerated(RecurringTransaction $rec): int
    {
        $updated = 0;
        foreach ($this->transactionRepo->findByRecurringSource($rec) as $tx) {
            $tx->setName($rec->getName());
            $tx->setDescription($rec->getDescription());
            $tx->setAmount($rec->getAmount());
            $tx->setType($rec->getType());
            $tx->setCategory($rec->getCategory());
            $updated++;
        }

        return $updated;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return array<string, true> Fechas 'Y-m-d' ya generadas para la recurrente */
    private function existingDates(RecurringTransaction $rec): array
    {
        // Recurrente aún sin persistir (create): no puede tener transacciones generadas.
        if ($rec->getId() === null) {
            return [];
        }

        $dates = [];
        foreach ($this->transactionRepo->findByRecurringSource($rec) as $tx) {
            $dates[$tx->getDate()->format('Y-m-d')] = true;
        }

        return $dates;
    }

    private function newTransactionFor(RecurringTransaction $rec, \DateTimeImmutable $date): Transaction
    {
        $tx = new Transaction($rec->getAccount(), $rec->getCreatedBy());
        $tx->setType($rec->getType());
        $tx->setAmount($rec->getAmount());
        // La columna es de tipo Doctrine "date" (requiere DateTime mutable)
        $tx->setDate(\DateTime::createFromImmutable($date));
        $tx->setName($rec->getName());
        $tx->setDescription($rec->getDescription());
        $tx->setCategory($rec->getCategory());
        $tx->setRecurringSource($rec);

        return $tx;
    }

    /** @return \DateTimeImmutable[] */
    private function dailyOccurrences(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $dates = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $dates[] = $d;
        }

        return $dates;
    }

    /**
     * Semanal: startDate + 7·k (mismo día de la semana que startDate).
     *
     * @return \DateTimeImmutable[]
     */
    private function weeklyOccurrences(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $start): array
    {
        // Primer elemento de la serie >= from, sin iterar semana a semana
        $first = $start;
        if ($first < $from) {
            $daysBehind = (int) $start->diff($from)->days;
            $first = $start->modify('+' . (int) (ceil($daysBehind / 7) * 7) . ' days');
        }

        $dates = [];
        for ($d = $first; $d <= $to; $d = $d->modify('+7 days')) {
            $dates[] = $d;
        }

        return $dates;
    }

    /** @return \DateTimeImmutable[] */
    private function monthlyOccurrences(\DateTimeImmutable $from, \DateTimeImmutable $to, int $day): array
    {
        $dates = [];
        $month = new \DateTimeImmutable($from->format('Y-m-01'));
        while (true) {
            $occ = $this->clampToMonth((int) $month->format('Y'), (int) $month->format('n'), $day);
            if ($occ > $to) {
                break;
            }
            if ($occ >= $from) {
                $dates[] = $occ;
            }
            $month = $month->modify('first day of next month');
        }

        return $dates;
    }

    /**
     * Anual: el día y mes de startDate, cada año.
     *
     * @return \DateTimeImmutable[]
     */
    private function yearlyOccurrences(\DateTimeImmutable $from, \DateTimeImmutable $to, int $day, int $anchorMonth): array
    {
        $dates = [];
        for ($y = (int) $from->format('Y'); $y <= (int) $to->format('Y'); $y++) {
            $occ = $this->clampToMonth($y, $anchorMonth, $day);
            if ($occ >= $from && $occ <= $to) {
                $dates[] = $occ;
            }
        }

        return $dates;
    }

    /** Día 29-31 en meses cortos → último día del mes. */
    private function clampToMonth(int $year, int $month, int $day): \DateTimeImmutable
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $daysInMonth = (int) $first->format('t');

        return $first->setDate($year, $month, min($day, $daysInMonth));
    }
}
