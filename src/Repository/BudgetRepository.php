<?php

namespace App\Repository;

use App\Budget\BudgetPeriod;
use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Enum\Frequency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Budget>
 */
class BudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Budget::class);
    }

    /**
     * Todos los presupuestos de una cuenta, con su categoría y sus tramos ya
     * cargados. Primero los de toda la cuenta y luego por categoría.
     *
     * @return list<Budget>
     */
    public function findByAccount(Account $account): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('c', 'a')
            ->leftJoin('b.category', 'c')
            ->leftJoin('b.amounts', 'a')
            ->where('b.account = :account')
            ->setParameter('account', $account)
            ->addOrderBy('CASE WHEN b.category IS NULL THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('b.type', 'DESC') // expense antes que income
            ->addOrderBy('b.startDate', 'ASC')
            ->addOrderBy('a.effectiveFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Presupuestos con el mismo tipo, ámbito y frecuencia cuya vigencia se
     * solapa con [first, last] (last null = sin fin). Es la regla de unicidad:
     * no cabe en un índice único, así que se comprueba aquí.
     *
     * @return list<Budget>
     */
    public function findOverlapping(
        Account $account,
        string $type,
        Frequency $frequency,
        ?Category $category,
        BudgetPeriod $first,
        ?BudgetPeriod $last,
        ?Budget $exclude = null,
    ): array {
        $qb = $this->createQueryBuilder('b')
            ->where('b.account = :account')
            ->andWhere('b.type = :type')
            ->andWhere('b.frequency = :frequency')
            ->andWhere('b.endDate IS NULL OR b.endDate >= :firstStart')
            ->setParameter('account', $account)
            ->setParameter('type', $type)
            ->setParameter('frequency', $frequency->value)
            ->setParameter('firstStart', $first->start);

        if ($category === null) {
            $qb->andWhere('b.category IS NULL');
        } else {
            $qb->andWhere('b.category = :category')->setParameter('category', $category);
        }
        if ($last !== null) {
            $qb->andWhere('b.startDate <= :lastEnd')->setParameter('lastEnd', $last->end);
        }
        if ($exclude?->getId() !== null) {
            $qb->andWhere('b.id != :exclude')->setParameter('exclude', $exclude->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Nº de presupuestos por categoría de una cuenta: el aviso al borrar una
     * categoría, que se los lleva por delante (ON DELETE CASCADE).
     *
     * @return array<int, int> id de categoría => nº de presupuestos
     */
    public function countByCategoryForAccount(Account $account): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.category) AS categoryId', 'COUNT(b.id) AS cnt')
            ->where('b.account = :account')
            ->andWhere('b.category IS NOT NULL')
            ->setParameter('account', $account)
            ->groupBy('b.category')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['categoryId']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function countByCategory(Category $category): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
