<?php

namespace App\Repository;

use App\Entity\RecurringTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RecurringTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTransaction::class);
    }

    /**
     * Devuelve las transacciones recurrentes activas que podrían necesitar generación.
     *
     * No filtra por endDate: si el comando estuvo días sin ejecutarse y la
     * recurrente expiró entre medias, aún hay que generar las ocurrencias
     * pendientes hasta endDate (el recorte lo hace el materializador).
     */
    public function findActiveForGeneration(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isActive = true')
            ->andWhere('r.startDate <= :today')
            ->setParameter('today', new \DateTime('today'))
            ->getQuery()
            ->getResult();
    }

    /**
     * Número de recurrentes vigentes de una cuenta concreta: activas y que aún
     * no han terminado a fecha de hoy.
     *
     * Una recurrente con endDate pasada sigue con isActive = true (no se apaga
     * sola), pero ya no genera nada, así que contarla inflaba el KPI. endDate es
     * inclusiva, igual que en {@see RecurringTransaction::hasExpired} y en
     * {@see findByAccount}.
     */
    public function countActiveByAccount($account): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.account = :account')
            ->andWhere('r.isActive = true')
            ->andWhere('r.endDate IS NULL OR r.endDate >= :today')
            ->setParameter('account', $account)
            ->setParameter('today', new \DateTime('today'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recurrentes de una cuenta concreta.
     *
     * Orden: primero las vigentes (activas y sin fecha fin pasada), luego las
     * pausadas o finalizadas; dentro de cada grupo, la de inicio más reciente
     * arriba. endDate es inclusiva, igual que en RecurringTransaction::hasExpired.
     */
    public function findByAccount($account): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('CASE WHEN r.isActive = true AND (r.endDate IS NULL OR r.endDate >= :today) THEN 1 ELSE 0 END AS HIDDEN isRunning')
            ->where('r.account = :account')
            ->setParameter('account', $account)
            ->setParameter('today', new \DateTime('today'))
            ->orderBy('isRunning', 'DESC')
            ->addOrderBy('r.startDate', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
