<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Pagination\PageSize;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\AccountService;
use App\Service\CategorySuggester;
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
    /** Listado completo: se asume que se viene a revisar movimientos en bloque. */
    private const PER_PAGE_DEFAULT = 25;

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

        $account = $this->accountService->resolveCurrentAccount($request, $accounts);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $dateFrom = null;
        $dateTo   = null;
        if ($raw = $request->query->get('date_from', '')) {
            $dateFrom = \DateTime::createFromFormat('Y-m-d', $raw) ?: null;
            if ($dateFrom) {
                $dateFrom->setTime(0, 0, 0);
            }
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

        $categories = $this->categoryRepo->findAllByAccount($account);

        // Descarta un filtro de categoría que no pertenece a la cuenta actual
        // (arrastrado al cambiar de cuenta, o desde una URL antigua/compartida)
        if ($categoryId !== null && !in_array($categoryId, array_map(fn($c) => $c->getId(), $categories), true)) {
            $categoryId = null;
        }
        $search = trim($request->query->get('search', '')) ?: null;

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

        $perPage = PageSize::fromRequest($request, self::PER_PAGE_DEFAULT);

        $query = $this->transactionRepo->findByFiltersQuery(
            $account, $dateFrom, $dateTo, $type, $categoryId, $noCategory, $search, $sortField, $sortDir, $amountFrom, $amountTo
        );

        $pagination = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            $perPage
        );

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
            'currentAmountFrom'  => $amountFrom,
            'currentAmountTo'    => $amountTo,
            'sortField'          => $sortField,
            'sortDir'            => $sortDir,
            'perPage'            => $perPage,
            'perPageOptions'     => PageSize::OPTIONS,
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
            'suggest'  => $isNew, // sugerir categoría solo al crear
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($transaction);
            }
            $this->em->flush();

            $redirectUrl = $request->request->get('_redirect_url');
            $dashboardUrl = $this->generateUrl('dashboard', ['account' => $account->getId()]);
            // Sin CTA al dashboard si el movimiento se creó desde el propio dashboard
            $backToDashboard = $redirectUrl && str_starts_with($redirectUrl, $this->generateUrl('dashboard'));

            if ($isNew && !$backToDashboard) {
                $this->addFlash('success_html', sprintf(
                    'Movimiento registrado. <a href="%s" class="alert-link">Ver en el dashboard</a>.',
                    $dashboardUrl
                ));
            } else {
                $this->addFlash('success', $isNew ? 'Movimiento registrado.' : 'Movimiento actualizado.');
            }

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

    /**
     * Sugerencia de categoría para un movimiento nuevo, según el historial de
     * la cuenta. Devuelve el chip HTML a inyectar (patrón AJAX de user-search),
     * o 204 sin cuerpo cuando no hay nada que sugerir.
     */
    #[Route('/suggest-category', name: 'suggest_category', methods: ['GET'])]
    public function suggestCategory(Request $request, CategorySuggester $suggester): Response
    {
        $account = $this->em->getRepository(Account::class)->find($request->query->getInt('account'));
        if (!$account) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }
        // Solo quien puede crear movimientos en la cuenta recibe sugerencias:
        // evita filtrar nombres/categorías de cuentas ajenas.
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        $name = trim((string) $request->query->get('name', ''));
        $type = (string) $request->query->get('type', Transaction::TYPE_EXPENSE);

        $suggestion = $suggester->suggestFor($account, $name, $type);
        if ($suggestion === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('transaction/_category_suggestion.html.twig', [
            'suggestion' => $suggestion,
        ]);
    }

    #[Route('/{id}/summary', name: 'summary', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function summary(Transaction $transaction, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $transaction->getAccount());

        // URL a la que volver tras eliminar desde el modal (conserva filtros/página)
        $redirect = (string) $request->query->get('redirect', '');
        if (!str_starts_with($redirect, '/')) {
            $redirect = null;
        }

        return $this->render('transaction/_summary.html.twig', [
            'tx' => $transaction,
            'canEdit' => $this->isGranted('ACCOUNT_EDIT', $transaction->getAccount()),
            'redirectUrl' => $redirect,
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
