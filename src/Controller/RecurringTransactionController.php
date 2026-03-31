<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\RecurringTransaction;
use App\Form\RecurringTransactionType;
use App\Repository\RecurringTransactionRepository;
use App\Service\AccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/recurring', name: 'recurring_')]
class RecurringTransactionController extends AbstractController
{
    public function __construct(
        private AccountService $accountService,
        private RecurringTransactionRepository $recurringRepo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $accounts = $this->accountService->getActiveAccountsForUser($user);

        if (empty($accounts)) {
            return $this->redirectToRoute('account_create');
        }

        $accountId = $request->query->getInt('account', $accounts[0]->getId());
        $account = $this->em->getRepository(Account::class)->find($accountId);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $recurrings = $this->recurringRepo->findByAccount($account);

        return $this->render('recurring/index.html.twig', [
            'accounts'       => $accounts,
            'currentAccount' => $account,
            'recurrings'     => $recurrings,
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request): Response
    {
        $accountId = $request->query->getInt('account');
        $account = $this->em->getRepository(Account::class)->find($accountId);

        if (!$account) {
            $this->addFlash('error', 'Cuenta no encontrada.');
            return $this->redirectToRoute('recurring_index');
        }

        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        $recurring = new RecurringTransaction($account, $this->getUser());
        $form = $this->createForm(RecurringTransactionType::class, $recurring, [
            'currency' => $account->getCurrency(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($recurring);
            $this->em->flush();

            $this->addFlash('success', 'Transacción recurrente creada.');
            return $this->redirectToRoute('recurring_index', ['account' => $account->getId()]);
        }

        return $this->render('recurring/create.html.twig', [
            'form'    => $form,
            'account' => $account,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(RecurringTransaction $recurring, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $recurring->getAccount());

        $form = $this->createForm(RecurringTransactionType::class, $recurring, [
            'currency' => $recurring->getAccount()->getCurrency(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Transacción recurrente actualizada.');
            return $this->redirectToRoute('recurring_index', ['account' => $recurring->getAccount()->getId()]);
        }

        return $this->render('recurring/edit.html.twig', [
            'form'      => $form,
            'recurring' => $recurring,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(RecurringTransaction $recurring, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $recurring->getAccount());

        if ($this->isCsrfTokenValid('toggle' . $recurring->getId(), $request->request->get('_token'))) {
            $recurring->setIsActive(!$recurring->isActive());
            $this->em->flush();

            $status = $recurring->isActive() ? 'activada' : 'desactivada';
            $this->addFlash('success', "Transacción recurrente $status.");
        }

        return $this->redirectToRoute('recurring_index', ['account' => $recurring->getAccount()->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(RecurringTransaction $recurring, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $recurring->getAccount());
        $accountId = $recurring->getAccount()->getId();

        if ($this->isCsrfTokenValid('delete' . $recurring->getId(), $request->request->get('_token'))) {
            $this->em->remove($recurring);
            $this->em->flush();
            $this->addFlash('success', 'Transacción recurrente eliminada.');
        }

        return $this->redirectToRoute('recurring_index', ['account' => $accountId]);
    }
}
