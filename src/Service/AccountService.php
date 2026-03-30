<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountMember;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

class AccountService
{
    public function __construct(
        private EntityManagerInterface $em,
        private FriendshipRepository $friendshipRepository,
        private TransactionRepository $transactionRepository,
    ) {}

    /**
     * Crea una cuenta y asigna al creador como owner.
     */
    public function createAccount(User $owner, string $name, ?string $description = null, string $currency = 'EUR'): Account
    {
        $account = new Account();
        $account->setName($name);
        $account->setDescription($description);
        $account->setCurrency($currency);

        $member = new AccountMember($account, $owner, AccountMember::ROLE_OWNER);

        $this->em->persist($account);
        $this->em->persist($member);
        $this->em->flush();

        return $account;
    }

    /**
     * Añade un miembro a la cuenta. Requiere amistad aceptada con el owner.
     */
    public function addMember(Account $account, User $owner, User $newUser, string $role = AccountMember::ROLE_EDITOR): AccountMember
    {
        // Validar que quien invita es owner
        $ownerMember = $this->findActiveMember($account, $owner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede añadir miembros.');
        }

        // Validar amistad
        $friendship = $this->friendshipRepository->findBetween($owner, $newUser);
        if (!$friendship || !$friendship->isAccepted()) {
            throw new \LogicException('Debes ser amigo de esta persona para invitarla.');
        }

        // Comprobar si ya fue miembro (soft delete → rejoin)
        $existingMember = $this->findMemberIncludingLeft($account, $newUser);
        if ($existingMember) {
            if (!$existingMember->hasLeft()) {
                throw new \LogicException('Este usuario ya es miembro de la cuenta.');
            }
            $existingMember->rejoin($role);
            $this->em->flush();
            return $existingMember;
        }

        $member = new AccountMember($account, $newUser, $role);
        $this->em->persist($member);
        $this->em->flush();

        return $member;
    }

    /**
     * Un miembro abandona la cuenta (soft delete).
     * El owner no puede abandonar su propia cuenta.
     */
    public function leaveAccount(Account $account, User $user): void
    {
        $member = $this->findActiveMember($account, $user);
        if (!$member) {
            throw new \LogicException('No eres miembro de esta cuenta.');
        }

        if ($member->isOwner()) {
            throw new \LogicException('El propietario no puede abandonar la cuenta. Transfiere la propiedad primero.');
        }

        $member->leave();
        $this->em->flush();
    }

    /**
     * Transfiere la propiedad a otro miembro activo.
     */
    public function transferOwnership(Account $account, User $currentOwner, User $newOwner): void
    {
        $ownerMember = $this->findActiveMember($account, $currentOwner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('No eres el propietario de esta cuenta.');
        }

        $newOwnerMember = $this->findActiveMember($account, $newOwner);
        if (!$newOwnerMember) {
            throw new \LogicException('El nuevo propietario debe ser miembro activo de la cuenta.');
        }

        $ownerMember->setRole(AccountMember::ROLE_EDITOR);
        $newOwnerMember->setRole(AccountMember::ROLE_OWNER);
        $this->em->flush();
    }

    /**
     * Calcula el balance de la cuenta.
     */
    public function getBalance(Account $account): string
    {
        return $this->transactionRepository->calculateBalance($account);
    }

    /**
     * Busca un miembro activo (sin leftAt) de una cuenta.
     */
    public function findActiveMember(Account $account, User $user): ?AccountMember
    {
        return $this->em->getRepository(AccountMember::class)->findOneBy([
            'account' => $account,
            'user' => $user,
            'leftAt' => null,
        ]);
    }

    /**
     * Busca un miembro incluyendo los que han salido.
     */
    private function findMemberIncludingLeft(Account $account, User $user): ?AccountMember
    {
        return $this->em->getRepository(AccountMember::class)->findOneBy([
            'account' => $account,
            'user' => $user,
        ]);
    }
}
