<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountMember;
use App\Entity\User;
use App\Form\AccountType;
use App\Service\AccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/account', name: 'account_')]
class AccountController extends AbstractController
{
    public function __construct(
        private AccountService $accountService,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $accounts = $this->accountService->getActiveAccountsForUser($this->getUser());

        return $this->render('account/index.html.twig', [
            'accounts' => $accounts,
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request): Response
    {
        $account = new Account();
        $form = $this->createForm(AccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->accountService->createAccount(
                $this->getUser(),
                $account->getName(),
                $account->getDescription(),
                $account->getCurrency()
            );

            $this->addFlash('success', 'Cuenta creada correctamente.');
            return $this->redirectToRoute('account_index');
        }

        return $this->render('account/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(Account $account): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $members = $account->getActiveMembers();
        $balance = $this->accountService->getBalance($account);
        $currentMember = $this->accountService->getMemberRole($account, $this->getUser());

        return $this->render('account/show.html.twig', [
            'account' => $account,
            'members' => $members,
            'balance' => $balance,
            'currentMember' => $currentMember,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(Account $account, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_MANAGE', $account);

        $form = $this->createForm(AccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Cuenta actualizada.');
            return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
        }

        return $this->render('account/edit.html.twig', [
            'account' => $account,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Account $account, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_MANAGE', $account);

        if ($this->isCsrfTokenValid('delete' . $account->getId(), $request->request->get('_token'))) {
            $this->accountService->deleteAccount($account, $this->getUser());
            $this->addFlash('success', 'Cuenta eliminada.');
        }

        return $this->redirectToRoute('account_index');
    }

    #[Route('/{id}/invite', name: 'invite', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function invite(Account $account, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_MANAGE', $account);

        $userId = $request->request->getInt('user_id');
        $role = $request->request->get('role', AccountMember::ROLE_EDITOR);
        $user = $this->em->getRepository(User::class)->find($userId);

        if (!$user) {
            $this->addFlash('error', 'Usuario no encontrado.');
            return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
        }

        try {
            $this->accountService->addMember($account, $this->getUser(), $user, $role);
            $this->addFlash('success', $user->getNickname() . ' ha sido añadido a la cuenta.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
    }

    #[Route('/{id}/remove-member/{userId}', name: 'remove_member', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function removeMember(Account $account, int $userId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_MANAGE', $account);

        $target = $this->em->getRepository(User::class)->find($userId);
        if (!$target) {
            $this->addFlash('error', 'Usuario no encontrado.');
            return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
        }

        if ($this->isCsrfTokenValid('remove' . $userId, $request->request->get('_token'))) {
            try {
                $this->accountService->removeMember($account, $this->getUser(), $target);
                $this->addFlash('success', 'Miembro eliminado de la cuenta.');
            } catch (\LogicException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
    }

    #[Route('/{id}/leave', name: 'leave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function leave(Account $account, Request $request): Response
    {
        if ($this->isCsrfTokenValid('leave' . $account->getId(), $request->request->get('_token'))) {
            try {
                $this->accountService->leaveAccount($account, $this->getUser());
                $this->addFlash('success', 'Has abandonado la cuenta.');
            } catch (\LogicException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('account_index');
    }

    #[Route('/{id}/transfer-ownership', name: 'transfer_ownership', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transferOwnership(Account $account, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_MANAGE', $account);

        $newOwnerId = $request->request->getInt('new_owner_id');
        $newOwner = $this->em->getRepository(User::class)->find($newOwnerId);

        if (!$newOwner) {
            $this->addFlash('error', 'Usuario no encontrado.');
            return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
        }

        if ($this->isCsrfTokenValid('transfer' . $account->getId(), $request->request->get('_token'))) {
            try {
                $this->accountService->transferOwnership($account, $this->getUser(), $newOwner);
                $this->addFlash('success', 'Propiedad transferida a ' . $newOwner->getNickname() . '.');
            } catch (\LogicException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
    }
}
