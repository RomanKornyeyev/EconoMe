<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountInvitation>
 */
class AccountInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountInvitation::class);
    }

    /**
     * Devuelve la invitación (en cualquier estado) de un usuario a una cuenta.
     */
    public function findForAccountAndInvitee(Account $account, User $invitee): ?AccountInvitation
    {
        return $this->findOneBy([
            'account' => $account,
            'invitee' => $invitee,
        ]);
    }

    /**
     * Invitaciones pendientes recibidas por un usuario (todas sus cuentas).
     *
     * @return AccountInvitation[]
     */
    public function findPendingReceivedBy(User $user): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.account', 'a')
            ->where('i.invitee = :user')
            ->andWhere('i.status = :status')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('status', AccountInvitation::STATUS_PENDING)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Invitaciones pendientes emitidas para una cuenta concreta.
     *
     * @return AccountInvitation[]
     */
    public function findPendingByAccount(Account $account): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.account = :account')
            ->andWhere('i.status = :status')
            ->setParameter('account', $account)
            ->setParameter('status', AccountInvitation::STATUS_PENDING)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ids de usuarios con invitación pendiente en una cuenta (para excluirlos
     * de la búsqueda de invitación).
     *
     * @return int[]
     */
    public function findPendingInviteeIds(Account $account): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.invitee) AS inviteeId')
            ->where('i.account = :account')
            ->andWhere('i.status = :status')
            ->setParameter('account', $account)
            ->setParameter('status', AccountInvitation::STATUS_PENDING)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'inviteeId'));
    }

    public function countPendingReceivedBy(User $user): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->join('i.account', 'a')
            ->where('i.invitee = :user')
            ->andWhere('i.status = :status')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('status', AccountInvitation::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
