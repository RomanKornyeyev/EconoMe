<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountMember;
use App\Entity\Category;
use App\Entity\User;
use App\Repository\CategoryTemplateRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class AccountService
{
    private const SESSION_ACCOUNT_KEY = 'current_account_id';

    public function __construct(
        private EntityManagerInterface $em,
        private TransactionRepository $transactionRepository,
        private CategoryTemplateRepository $templateRepo,
    ) {}

    /**
     * Persiste una cuenta ya hidratada (por el formulario), asigna al creador
     * como owner y, opcionalmente, copia sus CategoryTemplates.
     */
    public function createAccount(User $owner, Account $account, bool $copyTemplates = true): Account
    {
        $member = new AccountMember($account, $owner, AccountMember::ROLE_OWNER);

        $this->em->persist($account);
        $this->em->persist($member);

        if ($copyTemplates) {
            $this->copyTemplatesToAccount($owner, $account);
        }

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
            $category->setColor($template->getColor());
            $category->setType($template->getType());
            $this->em->persist($category);
        }
    }

    /**
     * Da de alta a un usuario como miembro tras aceptar una invitación.
     * Las validaciones (owner, amistad) ya se hicieron al crear la invitación,
     * por lo que aquí solo se resuelve el alta o el rejoin.
     */
    public function activateMembership(Account $account, User $user, string $role = AccountMember::ROLE_EDITOR): AccountMember
    {
        $existingMember = $this->findMemberIncludingLeft($account, $user);
        if ($existingMember) {
            if (!$existingMember->hasLeft()) {
                throw new \LogicException('Este usuario ya es miembro de la cuenta.');
            }
            $existingMember->rejoin($role);
            $this->em->flush();
            return $existingMember;
        }

        $member = new AccountMember($account, $user, $role);
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
     * Resuelve la cuenta "actual" para las vistas con selector de cuenta.
     * Prioridad: ?account= explícito → última seleccionada (sesión) → primera cuenta.
     *
     * El valor de sesión solo se usa si la cuenta sigue entre las activas del
     * usuario, de modo que un contexto obsoleto (expulsión, borrado, abandono)
     * nunca provoque un 403; se descarta y se cae a la primera cuenta.
     *
     * Devuelve null solo si ?account= apunta a una cuenta fuera de la lista:
     * en ese caso el controlador debe denegar, no elegir otra en silencio.
     */
    public function resolveCurrentAccount(Request $request, array $accounts): ?Account
    {
        if (empty($accounts)) {
            return null;
        }

        $session = $request->getSession();

        if ($requestedId = $request->query->getInt('account')) {
            $account = $this->findAccountInList($accounts, $requestedId);
            if ($account) {
                $session->set(self::SESSION_ACCOUNT_KEY, $account->getId());
            }
            return $account;
        }

        if ($sessionId = $session->get(self::SESSION_ACCOUNT_KEY)) {
            if ($account = $this->findAccountInList($accounts, (int) $sessionId)) {
                return $account;
            }
            $session->remove(self::SESSION_ACCOUNT_KEY);
        }

        return $accounts[0];
    }

    /**
     * Marca una cuenta como la última seleccionada (para vistas que reciben
     * la cuenta por ruta en lugar de por query param, como account_show).
     */
    public function rememberCurrentAccount(Request $request, Account $account): void
    {
        $request->getSession()->set(self::SESSION_ACCOUNT_KEY, $account->getId());
    }

    private function findAccountInList(array $accounts, int $id): ?Account
    {
        foreach ($accounts as $account) {
            if ($account->getId() === $id) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Devuelve las cuentas donde el usuario es miembro activo,
     * excluyendo las que están en la papelera (soft delete).
     */
    public function getActiveAccountsForUser(User $user): array
    {
        $members = $this->em->getRepository(AccountMember::class)->findBy([
            'user'   => $user,
            'leftAt' => null,
        ]);

        return array_values(array_filter(
            array_map(fn(AccountMember $m) => $m->getAccount(), $members),
            fn(Account $a) => !$a->isDeleted(),
        ));
    }

    /**
     * Cuentas en la papelera de las que el usuario es propietario.
     * Solo el owner puede ver y gestionar (restaurar/purgar) una cuenta borrada.
     */
    public function getDeletedAccountsOwnedByUser(User $user): array
    {
        $members = $this->em->getRepository(AccountMember::class)->findBy([
            'user'   => $user,
            'leftAt' => null,
        ]);

        // $members ya son las membresías activas del usuario: se filtra por owner
        // sobre el propio member, sin volver a consultar por cada cuenta.
        return array_values(array_map(
            fn(AccountMember $m) => $m->getAccount(),
            array_filter($members, fn(AccountMember $m) => $m->isOwner() && $m->getAccount()->isDeleted()),
        ));
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
     * Envía una cuenta a la papelera (soft delete). Recuperable por el owner.
     */
    public function deleteAccount(Account $account, User $owner): void
    {
        $ownerMember = $this->findActiveMember($account, $owner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede eliminar la cuenta.');
        }

        if ($account->isDeleted()) {
            return;
        }

        $account->softDelete();
        $this->em->flush();
    }

    /**
     * Restaura una cuenta de la papelera (solo el owner).
     */
    public function restoreAccount(Account $account, User $owner): void
    {
        $ownerMember = $this->findActiveMember($account, $owner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede restaurar la cuenta.');
        }

        $account->restore();
        $this->em->flush();
    }

    /**
     * Elimina una cuenta de forma permanente (solo el owner).
     * Requiere que esté en la papelera y que haya pasado el enfriamiento.
     */
    public function permanentlyDeleteAccount(Account $account, User $owner): void
    {
        $ownerMember = $this->findActiveMember($account, $owner);
        if (!$ownerMember || !$ownerMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede eliminar la cuenta.');
        }

        if (!$account->isDeleted()) {
            throw new \LogicException('La cuenta debe estar en la papelera antes de eliminarla permanentemente.');
        }

        if (!$account->canBePurged()) {
            throw new \LogicException('Aún no puedes eliminar esta cuenta de forma permanente. Espera a que termine el periodo de seguridad.');
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
