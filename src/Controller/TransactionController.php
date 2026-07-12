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
            return $this->render('transaction/no_account.html.twig');
        }

        $accountId = $request->query->getInt('account', $accounts[0]->getId());
        $account = $this->em->getRepository(Account::class)->find($accountId);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $dateFrom = null;
        $dateTo   = null;
        if ($raw = $request->query->get('date_from', '')) {
            $dateFrom = \DateTime::createFromFormat('Y-m-d', $raw) ?: null;
        }
        if ($raw = $request->query->get('date_to', '')) {
            $dateTo = \DateTime::createFromFormat('Y-m-d', $raw) ?: null;
            if ($dateTo) {
                $dateTo->setTime(23, 59, 59);
            }
        }
        $type = $request->query->get('type') ?: null;
        $categoryRaw = $request->query->get('category', '');
        $noCategory = ($categoryRaw === '-1');
        $categoryId = (!$noCategory && $categoryRaw !== '') ? (int) $categoryRaw : null;
        $search = trim($request->query->get('search', '')) ?: null;
        $desc   = trim($request->query->get('desc', '')) ?: null;

        $amountFrom = null;
        $amountTo   = null;
        if ($request->query->get('amount_from', '') !== '') {
            $amountFrom = (float) $request->query->get('amount_from');
        }
        if ($request->query->get('amount_to', '') !== '') {
            $amountTo = (float) $request->query->get('amount_to');
        }

        $allowedSorts = ['date', 'name', 'amount', 'type', 'category'];
        $sortField = $request->query->getString('sortBy', 'date');
        $sortDir   = $request->query->getString('sortDir', 'desc');
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'date';
        }

        $query = $this->transactionRepo->findByFiltersQuery(
            $account, $dateFrom, $dateTo, $type, $categoryId, $noCategory, $search, $desc, $sortField, $sortDir, $amountFrom, $amountTo
        );

        $pagination = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            15
        );

        $categories = $this->categoryRepo->findAllByAccount($account);

        return $this->render('transaction/index.html.twig', [
            'accounts'        => $accounts,
            'currentAccount'  => $account,
            'pagination'      => $pagination,
            'categories'      => $categories,
            'dateFrom'        => $dateFrom,
            'dateTo'          => $dateTo,
            'currentType'     => $type,
            'currentCategory' => $noCategory ? -1 : $categoryId,
            'currentSearch'      => $search,
            'currentDesc'        => $desc,
            'currentAmountFrom'  => $amountFrom,
            'currentAmountTo'    => $amountTo,
            'sortField'          => $sortField,
            'sortDir'            => $sortDir,
        ]);
    }

    #[Route('/create', name: 'create')]
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, ?Transaction $transaction = null): Response
    {
        if ($transaction === null) {
            $accountId = $request->query->getInt('account');
            $account = $this->em->getRepository(Account::class)->find($accountId);

            if (!$account) {
                $this->addFlash('error', 'Cuenta no encontrada.');
                return $this->redirectToRoute('transaction_index');
            }

            $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);
            $transaction = new Transaction($account, $this->getUser());
            $isNew = true;
        } else {
            $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $transaction->getAccount());
            $account = $transaction->getAccount();
            $isNew = false;
        }

        $form = $this->createForm(TransactionType::class, $transaction, [
            'currency' => $account->getCurrency(),
            'account'  => $account,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($transaction);
            }
            $this->em->flush();
            $this->addFlash('success', $isNew ? 'Movimiento registrado.' : 'Movimiento actualizado.');

            $redirectUrl = $request->request->get('_redirect_url');
            if ($redirectUrl && str_starts_with($redirectUrl, '/')) {
                return $this->redirect($redirectUrl);
            }

            return $this->redirectToRoute('transaction_index', ['account' => $account->getId()]);
        }

        return $this->render('transaction/edit.html.twig', [
            'form'        => $form,
            'transaction' => $transaction,
            'account'     => $account,
            'isNew'       => $isNew,
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

        $redirectUrl = $request->request->get('_redirect_url');
        if ($redirectUrl && str_starts_with($redirectUrl, '/')) {
            return $this->redirect($redirectUrl);
        }

        return $this->redirectToRoute('transaction_index', ['account' => $accountId]);
    }

    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $ids = $request->request->all('ids');
        $deleted = 0;

        foreach ($ids as $id) {
            $transaction = $this->transactionRepo->find((int) $id);
            if ($transaction && $this->isGranted('ACCOUNT_EDIT', $transaction->getAccount())) {
                $this->em->remove($transaction);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->em->flush();
            $this->addFlash('success', $deleted === 1 ? '1 movimiento eliminado.' : "$deleted movimientos eliminados.");
        }

        $redirectUrl = $request->request->get('_redirect_url');
        if ($redirectUrl && str_starts_with($redirectUrl, '/')) {
            return $this->redirect($redirectUrl);
        }

        return $this->redirectToRoute('transaction_index');
    }
}
