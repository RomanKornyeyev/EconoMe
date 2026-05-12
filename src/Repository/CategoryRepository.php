<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Categorías raíz de una cuenta filtradas por tipo.
     */
    public function findRootByAccountAndType(Account $account, string $type): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.account = :account')
            ->andWhere('c.type = :type')
            ->andWhere('c.parent IS NULL')
            ->setParameter('account', $account)
            ->setParameter('type', $type)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todas las categorías raíz de una cuenta (para selects en formularios).
     */
    public function findAllByAccount(Account $account): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.account = :account')
            ->andWhere('c.parent IS NULL')
            ->setParameter('account', $account)
            ->orderBy('c.type', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
