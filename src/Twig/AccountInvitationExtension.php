<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\AccountInvitationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AccountInvitationExtension extends AbstractExtension
{
    public function __construct(
        private AccountInvitationRepository $invitationRepository,
        private Security $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_account_invitations_count', $this->getPendingCount(...)),
        ];
    }

    public function getPendingCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->invitationRepository->countPendingReceivedBy($user);
    }
}
