<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountInvitation;
use App\Entity\AccountMember;
use App\Entity\User;
use App\Repository\AccountInvitationRepository;
use App\Repository\FriendshipRepository;
use Doctrine\ORM\EntityManagerInterface;

class AccountInvitationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccountInvitationRepository $invitationRepository,
        private FriendshipRepository $friendshipRepository,
        private AccountService $accountService,
    ) {}

    /**
     * Crea (o reactiva) una invitación pendiente para que un amigo se una a la
     * cuenta. No lo añade directamente: el invitado deberá aceptarla.
     */
    public function invite(Account $account, User $inviter, User $invitee, string $role = AccountMember::ROLE_EDITOR): AccountInvitation
    {
        // Solo el owner puede invitar.
        $inviterMember = $this->accountService->findActiveMember($account, $inviter);
        if (!$inviterMember || !$inviterMember->isOwner()) {
            throw new \LogicException('Solo el propietario puede invitar miembros.');
        }

        if ($inviter === $invitee) {
            throw new \LogicException('No puedes invitarte a ti mismo.');
        }

        // Solo se puede invitar a amigos aceptados.
        $friendship = $this->friendshipRepository->findBetween($inviter, $invitee);
        if (!$friendship || !$friendship->isAccepted()) {
            throw new \LogicException('Solo puedes invitar a usuarios que sean amigos tuyos.');
        }

        // No invitar a quien ya es miembro activo.
        if ($this->accountService->findActiveMember($account, $invitee)) {
            throw new \LogicException('Este usuario ya es miembro de la cuenta.');
        }

        $existing = $this->invitationRepository->findForAccountAndInvitee($account, $invitee);

        if ($existing) {
            if ($existing->isPending()) {
                throw new \LogicException('Ya existe una invitación pendiente para este usuario.');
            }
            // Rechazada/cancelada previamente → reutilizamos la fila.
            $existing->renew($inviter, $role);
            $this->em->flush();
            return $existing;
        }

        $invitation = new AccountInvitation($account, $inviter, $invitee, $role);
        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }

    /**
     * El invitado acepta la invitación y se convierte en miembro.
     */
    public function accept(AccountInvitation $invitation, User $currentUser): void
    {
        if ($invitation->getInvitee() !== $currentUser) {
            throw new \LogicException('Solo el destinatario puede aceptar la invitación.');
        }

        if (!$invitation->isPending()) {
            throw new \LogicException('Esta invitación ya no está disponible.');
        }

        $this->accountService->activateMembership(
            $invitation->getAccount(),
            $currentUser,
            $invitation->getRole()
        );

        $invitation->accept();
        $this->em->flush();
    }

    /**
     * El invitado rechaza la invitación.
     */
    public function reject(AccountInvitation $invitation, User $currentUser): void
    {
        if ($invitation->getInvitee() !== $currentUser) {
            throw new \LogicException('Solo el destinatario puede rechazar la invitación.');
        }

        if (!$invitation->isPending()) {
            throw new \LogicException('Esta invitación ya no está disponible.');
        }

        $invitation->reject();
        $this->em->flush();
    }

    /**
     * El propietario retira una invitación pendiente.
     */
    public function cancel(AccountInvitation $invitation, User $currentUser): void
    {
        $member = $this->accountService->findActiveMember($invitation->getAccount(), $currentUser);
        if (!$member || !$member->isOwner()) {
            throw new \LogicException('Solo el propietario puede cancelar invitaciones.');
        }

        if (!$invitation->isPending()) {
            throw new \LogicException('Esta invitación ya no está disponible.');
        }

        $invitation->cancel();
        $this->em->flush();
    }
}
