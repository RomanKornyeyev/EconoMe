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
     */
    public function findActiveForGeneration(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isActive = true')
            ->andWhere('r.startDate <= :today')
            ->andWhere('r.endDate IS NULL OR r.endDate >= :today')
            ->setParameter('today', new \DateTime('today'))
            ->getQuery()
            ->getResult();
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
