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
    public function sumByCategory(
        Account $account,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $type = Transaction::TYPE_EXPENSE
    ): array {
        return $this->createQueryBuilder('t')
            ->select('c.name as categoryName', 'c.color as categoryColor', 'SUM(t.amount) as total')
            ->leftJoin('t.category', 'c')
            ->where('t.account = :account')
            ->andWhere('t.type = :type')
            ->andWhere('t.date >= :from')
            ->andWhere('t.date <= :to')
            ->setParameter('account', $account)
            ->setParameter('type', $type)
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
    private const SORT_FIELDS = [
        'date'     => 't.date',
        'name'     => 't.name',
        'amount'   => 'signedAmount',
        'type'     => 't.type',
        'category' => 'cat.name',
    ];

    public function findByFiltersQuery(
        Account $account,
        ?\DateTimeInterface $dateFrom = null,
        ?\DateTimeInterface $dateTo = null,
        ?string $type = null,
        ?int $categoryId = null,
        bool $noCategory = false,
        ?string $search = null,
        ?string $desc = null,
        string $sortField = 'date',
        string $sortDir = 'desc',
        ?float $amountFrom = null,
        ?float $amountTo = null,
    ): \Doctrine\ORM\QueryBuilder {
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        $sortCol = self::SORT_FIELDS[$sortField] ?? 't.date';

        $qb = $this->createQueryBuilder('t')
            ->addSelect("CASE WHEN t.type = 'expense' THEN (-1 * t.amount) ELSE t.amount END AS HIDDEN signedAmount")
            ->leftJoin('t.category', 'cat')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->orderBy($sortCol, $sortDir)
            ->addOrderBy('t.date', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC');

        if ($dateFrom) {
            $qb->andWhere('t.date >= :dateFrom')
               ->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('t.date <= :dateTo')
               ->setParameter('dateTo', $dateTo);
        }

        if ($type) {
            $qb->andWhere('t.type = :type')
               ->setParameter('type', $type);
        }

        if ($noCategory) {
            $qb->andWhere('t.category IS NULL');
        } elseif ($categoryId) {
            $qb->andWhere('t.category = :cat')
               ->setParameter('cat', $categoryId);
        }

        if ($search !== null) {
            $qb->andWhere('LOWER(t.name) LIKE LOWER(:search)')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($desc !== null) {
            $qb->andWhere('LOWER(t.description) LIKE LOWER(:desc)')
               ->setParameter('desc', '%' . $desc . '%');
        }

        if ($amountFrom !== null || $amountTo !== null) {
            $qb->setParameter('expenseType', Transaction::TYPE_EXPENSE);
        }

        if ($amountFrom !== null) {
            $qb->andWhere('CASE WHEN t.type = :expenseType THEN (-1 * t.amount) ELSE t.amount END >= :amountFrom')
               ->setParameter('amountFrom', $amountFrom);
        }

        if ($amountTo !== null) {
            $qb->andWhere('CASE WHEN t.type = :expenseType THEN (-1 * t.amount) ELSE t.amount END <= :amountTo')
               ->setParameter('amountTo', $amountTo);
        }

        return $qb;
    }

    /**
     * Meses distintos (1-12) en los que hay transacciones para una cuenta y año dados.
     */
    public function findMonthsWithTransactions(Account $account, int $year): array
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUBSTRING(t.date, 6, 2) as mo')
            ->where('t.account = :account')
            ->andWhere('SUBSTRING(t.date, 1, 4) = :year')
            ->setParameter('account', $account)
            ->setParameter('year', (string)$year)
            ->groupBy('mo')
            ->orderBy('mo', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn($r) => (int)$r['mo'], $result);
    }

    /**
     * Años distintos en los que hay transacciones para una cuenta.
     */
    public function findYearsWithTransactions(Account $account): array
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUBSTRING(t.date, 1, 4) as yr')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->groupBy('yr')
            ->orderBy('yr', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(fn($r) => (int)$r['yr'], $result);
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
