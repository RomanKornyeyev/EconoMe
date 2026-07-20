<?php

namespace App\Security\Voter;

use App\Entity\Account;
use App\Entity\User;
use App\Service\AccountService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AccountVoter extends Voter
{
    public const VIEW = 'ACCOUNT_VIEW';
    public const EDIT = 'ACCOUNT_EDIT';
    public const MANAGE = 'ACCOUNT_MANAGE';
    public const MANAGE_TRASH = 'ACCOUNT_MANAGE_TRASH'; // restaurar / purgar (owner)

    public function __construct(
        private AccountService $accountService,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::MANAGE, self::MANAGE_TRASH])
            && $subject instanceof Account;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $member = $this->accountService->findActiveMember($subject, $user);
        if (!$member) {
            return false;
        }

        // Cuenta en la papelera: solo el owner puede gestionarla (restaurar/purgar);
        // el resto de accesos quedan bloqueados hasta que se restaure.
        if ($subject->isDeleted()) {
            return $attribute === self::MANAGE_TRASH && $member->isOwner();
        }

        return match ($attribute) {
            self::VIEW => true, // cualquier miembro activo puede ver
            self::EDIT => $member->canCreateTransactions(), // owner o editor
            self::MANAGE => $member->canManageAccount(), // solo owner
            self::MANAGE_TRASH => false, // no aplica a cuentas activas
            default => false,
        };
    }
}
