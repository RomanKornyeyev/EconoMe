<?php

namespace App\Repository;

use App\Entity\CategoryTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryTemplate::class);
    }

    /**
     * Todas las plantillas de un usuario, ordenadas por tipo y nombre.
     */
    public function findAllByUser(User $user): array
    {
        return $this->createQueryBuilder('ct')
            ->where('ct.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ct.type', 'ASC')
            ->addOrderBy('ct.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
