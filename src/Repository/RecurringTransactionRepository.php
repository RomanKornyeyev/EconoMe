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
     * Número de recurrentes activas de una cuenta concreta.
     */
    public function countActiveByAccount($account): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.account = :account')
            ->andWhere('r.isActive = true')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recurrentes de una cuenta concreta.
     */
    public function findByAccount($account): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.account = :account')
            ->setParameter('account', $account)
            ->orderBy('r.isActive', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
