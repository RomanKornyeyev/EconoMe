<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\AccountService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/transaction', name: 'transaction_')]
class TransactionController extends AbstractController
{
    public function __construct(
        private AccountService $accountService,
        private TransactionRepository $transactionRepo,
        private CategoryRepository $categoryRepo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $user = $this->getUser();
        $accounts = $this->accountService->getActiveAccountsForUser($user);

        if (empty($accounts)) {
            return $this->redirectToRoute('account_create');
        }

        $accountId = $request->query->getInt('account', $accounts[0]->getId());
        $account = $this->em->getRepository(Account::class)->find($accountId);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $year = $request->query->getInt('year', (int) date('Y'));
        $month = $request->query->getInt('month') ?: null;
        $type = $request->query->get('type') ?: null;
        $categoryId = $request->query->getInt('category') ?: null;

        $query = $this->transactionRepo->findByFiltersQuery(
            $account, $year, $month, $type, $categoryId
        );

        $pagination = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            15
        );

        $categories = $this->categoryRepo->findAllByUser($user);

        return $this->render('transaction/index.html.twig', [
            'accounts'        => $accounts,
            'currentAccount'  => $account,
            'pagination'      => $pagination,
            'categories'      => $categories,
            'year'            => $year,
            'month'           => $month,
            'currentType'     => $type,
            'currentCategory' => $categoryId,
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request): Response
    {
        $accountId = $request->query->getInt('account');
        $account = $this->em->getRepository(Account::class)->find($accountId);

        if (!$account) {
            $this->addFlash('error', 'Cuenta no encontrada.');
            return $this->redirectToRoute('transaction_index');
        }

        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        $transaction = new Transaction($account, $this->getUser());
        $form = $this->createForm(TransactionType::class, $transaction, [
            'currency' => $account->getCurrency(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($transaction);
            $this->em->flush();

            $this->addFlash('success', 'Movimiento registrado.');

            if ($request->request->get('_redirect') === 'dashboard') {
                return $this->redirectToRoute('dashboard', ['account' => $account->getId()]);
            }

            return $this->redirectToRoute('transaction_index', ['account' => $account->getId()]);
        }

        return $this->render('transaction/create.html.twig', [
            'form'    => $form,
            'account' => $account,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(Transaction $transaction, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $transaction->getAccount());

        $form = $this->createForm(TransactionType::class, $transaction, [
            'currency' => $transaction->getAccount()->getCurrency(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Movimiento actualizado.');
            return $this->redirectToRoute('transaction_index', ['account' => $transaction->getAccount()->getId()]);
        }

        return $this->render('transaction/edit.html.twig', [
            'form'        => $form,
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Transaction $transaction, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $transaction->getAccount());
        $accountId = $transaction->getAccount()->getId();

        if ($this->isCsrfTokenValid('delete' . $transaction->getId(), $request->request->get('_token'))) {
            $this->em->remove($transaction);
            $this->em->flush();
            $this->addFlash('success', 'Movimiento eliminado.');
        }

        return $this->redirectToRoute('transaction_index', ['account' => $accountId]);
    }
}
