<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountMember;
use App\Entity\Category;
use App\Entity\User;
use App\Repository\CategoryTemplateRepository;
use App\Repository\FriendshipRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

class AccountService
{
    public function __construct(
        private EntityManagerInterface $em,
        private FriendshipRepository $friendshipRepository,
        private TransactionRepository $transactionRepository,
        private CategoryTemplateRepository $templateRepo,
    ) {}

    /**
     * Crea una cuenta, asigna al creador como owner y copia sus CategoryTemplates.
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

        $this->copyTemplatesToAccount($owner, $account);

        $this->em->flush();

        return $account;
    }

    /**
     * Copia las CategoryTemplates de un usuario como Categories de una cuenta.
     */
    private function copyTemplatesToAccount(User $user, Account $account): void
    {
        $templates = $this->templateRepo->findAllByUser($user);

        foreach ($templates as $template) {
            $category = new Category($account);
            $category->setName($template->getName());
            $category->setIcon($template->getIcon());
            $category->setColor($template->getColor());
            $category->setType($template->getType());
            $this->em->persist($category);
        }
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
     * Devuelve las cuentas donde el usuario es miembro activo.
     */
    public function getActiveAccountsForUser(User $user): array
    {
        $members = $this->em->getRepository(AccountMember::class)->findBy([
            'user'   => $user,
            'leftAt' => null,
        ]);

        return array_map(fn(AccountMember $m) => $m->getAccount(), $members);
    }

    /**
     * Calcula el balance de la cuenta.
     */
    public function getBalance(Account $account): string
    {
        return $this->transactionRepository->calculateBalance($account);
    }

    /**
     * Devuelve el AccountMember del usuario en una cuenta, o null.
     */
    public function getMemberRole(Account $account, User $user): ?AccountMember
    {
        return $this->findActiveMember($account, $user);
    }

    /**
     * Elimina un miembro de la cuenta (el owner lo expulsa).
     */
    public function removeMember(Account $account, User $owner, User $target): void
    {
        $ownerMember = $this->findActiveMember($account, $owner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede eliminar miembros.');
        }

        if ($owner === $target) {
            throw new \LogicException('No puedes eliminarte a ti mismo.');
        }

        $targetMember = $this->findActiveMember($account, $target);
        if (!$targetMember) {
            throw new \LogicException('El usuario no es miembro de esta cuenta.');
        }

        $targetMember->leave();
        $this->em->flush();
    }

    /**
     * Actualiza el rol de un miembro.
     */
    public function changeMemberRole(Account $account, User $owner, User $target, string $newRole): void
    {
        $ownerMember = $this->findActiveMember($account, $owner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede cambiar roles.');
        }

        $targetMember = $this->findActiveMember($account, $target);
        if (!$targetMember) {
            throw new \LogicException('El usuario no es miembro de esta cuenta.');
        }

        $targetMember->setRole($newRole);
        $this->em->flush();
    }

    /**
     * Elimina una cuenta completa (solo el owner).
     */
    public function deleteAccount(Account $account, User $owner): void
    {
        $ownerMember = $this->findActiveMember($account, $owner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede eliminar la cuenta.');
        }

        $this->em->remove($account);
        $this->em->flush();
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
