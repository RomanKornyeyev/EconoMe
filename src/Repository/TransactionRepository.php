<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Balance de una cuenta: sum(income) - sum(expense).
     */
    public function calculateBalance(Account $account): string
    {
        $result = $this->createQueryBuilder('t')
            ->select(
                "SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) as totalIncome",
                "SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) as totalExpense"
            )
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleResult();

        $income = $result['totalIncome'] ?? '0';
        $expense = $result['totalExpense'] ?? '0';

        return bcsub($income, $expense, 2);
    }

    /**
     * Transacciones de una cuenta en un rango de fechas, ordenadas por fecha descendente.
     */
    public function findByAccountAndDateRange(
        Account $account,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        return $this->createQueryBuilder('t')
            ->where('t.account = :account')
            ->andWhere('t.date >= :from')
            ->andWhere('t.date <= :to')
            ->setParameter('account', $account)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Gastos agrupados por categoría en un rango de fechas.
     * Para el gráfico donut del dashboard.
     */
    public function sumExpensesByCategory(
        Account $account,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        return $this->createQueryBuilder('t')
            ->select('c.name as categoryName', 'c.color as categoryColor', 'SUM(t.amount) as total')
            ->leftJoin('t.category', 'c')
            ->where('t.account = :account')
            ->andWhere('t.type = :type')
            ->andWhere('t.date >= :from')
            ->andWhere('t.date <= :to')
            ->setParameter('account', $account)
            ->setParameter('type', Transaction::TYPE_EXPENSE)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('c.id', 'c.name', 'c.color')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Query filtrada para paginación con KnpPaginator.
     */
    public function findByFiltersQuery(
        Account $account,
        ?int $year = null,
        ?int $month = null,
        ?string $type = null,
        ?int $categoryId = null
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('t')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC');

        if ($year && $month) {
            $from = new \DateTime("$year-$month-01");
            $to = (clone $from)->modify('last day of this month');
            $qb->andWhere('t.date >= :from AND t.date <= :to')
               ->setParameter('from', $from)
               ->setParameter('to', $to);
        }

        if ($type) {
            $qb->andWhere('t.type = :type')
               ->setParameter('type', $type);
        }

        if ($categoryId) {
            $qb->andWhere('t.category = :cat')
               ->setParameter('cat', $categoryId);
        }

        return $qb;
    }

    /**
     * Totales mensuales (income y expense) para un año dado.
     * Para el gráfico de barras del dashboard.
     */
    public function monthlyTotals(Account $account, int $year): array
    {
        return $this->createQueryBuilder('t')
            ->select(
                "SUBSTRING(t.date, 6, 2) as month",
                "SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) as totalIncome",
                "SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) as totalExpense"
            )
            ->where('t.account = :account')
            ->andWhere('SUBSTRING(t.date, 1, 4) = :year')
            ->setParameter('account', $account)
            ->setParameter('year', (string)$year)
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
