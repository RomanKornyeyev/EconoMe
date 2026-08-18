<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Pagination\PageSize;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Repository\RecurringTransactionRepository;
use App\Service\AccountService;
use App\Service\TransactionDraftFactory;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    /** Aquí la lista convive con los gráficos: se empieza corto. */
    private const PER_PAGE_DEFAULT = 10;

    #[Route('/dashboard', name: 'dashboard')]
    public function index(
        Request $request,
        AccountService $accountService,
        TransactionRepository $transactionRepo,
        RecurringTransactionRepository $recurringRepo,
        PaginatorInterface $paginator,
        TransactionDraftFactory $draftFactory,
        CategoryRepository $categoryRepo,
    ): Response {
        $user = $this->getUser();

        $accounts = $accountService->getActiveAccountsForUser($user);

        if (empty($accounts)) {
            return $this->render('dashboard/index.html.twig', [
                'hasAccounts' => false,
            ]);
        }

        $account = $accountService->resolveCurrentAccount($request, $accounts);
        if (!$account) {
            throw $this->createNotFoundException('Cuenta no encontrada');
        }
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $year  = $request->query->getInt('year', (int)date('Y'));
        $month = $request->query->getInt('month', (int)date('m'));

        [$from, $to, $periodLabel] = $this->resolvePeriod($year, $month);

        $availableYears = $transactionRepo->findYearsWithTransactions($account);
        if (!in_array($year, $availableYears)) {
            $availableYears[] = $year;
            rsort($availableYears);
        }

        $availableMonths = $transactionRepo->findMonthsWithTransactions($account, $year);

        $categoryType = $request->query->getString('categoryType', Transaction::TYPE_EXPENSE);
        if (!in_array($categoryType, [Transaction::TYPE_EXPENSE, Transaction::TYPE_INCOME])) {
            $categoryType = Transaction::TYPE_EXPENSE;
        }

        $balance            = $transactionRepo->calculateBalance($account);
        $monthlyData        = $transactionRepo->findByAccountAndDateRange($account, $from, $to);
        $expensesByCategory = $transactionRepo->sumByCategory($account, $from, $to, $categoryType);
        $yearlyTotals        = $transactionRepo->monthlyTotals($account, $year);
        $activeRecurrings    = $recurringRepo->countActiveByAccount($account);

        $periodIncome  = '0';
        $periodExpense = '0';
        foreach ($monthlyData as $tx) {
            if ($tx->isIncome()) {
                $periodIncome  = bcadd($periodIncome, $tx->getAmount(), 2);
            } else {
                $periodExpense = bcadd($periodExpense, $tx->getAmount(), 2);
            }
        }

        $allowedSorts = ['date', 'name', 'amount', 'type', 'category'];
        $sortField = $request->query->getString('sortBy', 'date');
        $sortDir   = $request->query->getString('sortDir', 'desc');
        if (!in_array($sortField, $allowedSorts, true)) {
            $sortField = 'date';
        }

        $pagination = $paginator->paginate(
            $transactionRepo->findByFiltersQuery($account, $from, $to, null, null, false, null, $sortField, $sortDir),
            $request->query->getInt('page', 1),
            PageSize::fromRequest($request, self::PER_PAGE_DEFAULT)
        );

        $transaction = $draftFactory->create($account, $user);
        $transactionForm = $this->createForm(TransactionType::class, $transaction, [
            'currency' => $account->getCurrency(),
            'account'  => $account,
            'suggest'  => true, // el modal siempre crea: sugerir categoría
            'action'   => $this->generateUrl('transaction_create', ['account' => $account->getId()]),
        ]);

        return $this->render('dashboard/index.html.twig', [
            'redirectUrl'        => $request->getRequestUri(),
            'hasAccounts'        => true,
            'accounts'           => $accounts,
            'currentAccount'     => $account,
            'balance'            => $balance,
            'periodIncome'       => $periodIncome,
            'periodExpense'      => $periodExpense,
            'activeRecurrings'   => $activeRecurrings,
            'transactions'       => $pagination,
            // Para el desplegable de «Categorizar» de la barra de acciones en bloque
            'categories'         => $categoryRepo->findAllByAccount($account),
            'perPageOptions'     => PageSize::OPTIONS,
            'sortField'          => $sortField,
            'sortDir'            => $sortDir,
            'expensesByCategory' => $expensesByCategory,
            'yearlyTotals'       => $yearlyTotals,
            'year'               => $year,
            'month'              => $month,
            'from'               => $from,
            'to'                 => $to,
            'periodLabel'        => $periodLabel,
            'availableYears'     => $availableYears,
            'availableMonths'    => $availableMonths,
            'categoryType'       => $categoryType,
            'transactionForm'    => $transactionForm,
        ]);
    }

    /**
     * Salto de un sector del donut al listado de movimientos.
     *
     * Hace escala en el servidor —en vez de enlazar directamente a
     * transaction_index— para poder dejar un flash que diga qué se ha filtrado
     * y ofrezca la vuelta al dashboard. Como el aviso viaja por sesión y no por
     * la URL, el listado conserva sus enlaces limpios y el flash no reaparece al
     * ordenar o paginar.
     */
    #[Route('/dashboard/categoria', name: 'dashboard_category_drilldown', methods: ['GET'])]
    public function categoryDrilldown(
        Request $request,
        AccountService $accountService,
        CategoryRepository $categoryRepo,
    ): Response {
        $accounts = $accountService->getActiveAccountsForUser($this->getUser());
        $account  = $accountService->resolveCurrentAccount($request, $accounts);
        if (!$account) {
            return $this->redirectToRoute('dashboard');
        }
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $year  = $request->query->getInt('year', (int)date('Y'));
        $month = $request->query->getInt('month', (int)date('m'));
        [$from, $to, $periodLabel] = $this->resolvePeriod($year, $month);

        $type = $request->query->getString('type', Transaction::TYPE_EXPENSE);
        if (!in_array($type, [Transaction::TYPE_EXPENSE, Transaction::TYPE_INCOME], true)) {
            $type = Transaction::TYPE_EXPENSE;
        }

        // Igual que en transaction_index: -1 es «sin categoría», y una categoría
        // que no sea de esta cuenta se descarta en lugar de filtrar por ella.
        $categoryRaw = $request->query->getString('category');
        $categoryParam = null;
        $categoryPart  = '';
        if ($categoryRaw === '-1') {
            $categoryParam = -1;
            $categoryPart  = ', sin categoría';
        } elseif ($categoryRaw !== '') {
            foreach ($categoryRepo->findAllByAccount($account) as $category) {
                if ($category->getId() === (int) $categoryRaw) {
                    $categoryParam = $category->getId();
                    $categoryPart  = ', con categoría '
                        . htmlspecialchars($category->getName(), ENT_QUOTES, 'UTF-8');
                    break;
                }
            }
        }

        $this->addFlash('info_html', sprintf(
            '<a href="%s" class="alert-link"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>'
            . ' &nbsp;&middot;&nbsp; Filtrados %s de %s%s.',
            htmlspecialchars($this->generateUrl('dashboard', [
                'account'      => $account->getId(),
                'year'         => $year,
                'month'        => $month,
                'categoryType' => $type,
            ]), ENT_QUOTES, 'UTF-8'),
            $type === Transaction::TYPE_INCOME ? 'ingresos' : 'gastos',
            htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'),
            $categoryPart,
        ));

        return $this->redirectToRoute('transaction_index', array_filter([
            'account'   => $account->getId(),
            'date_from' => $from->format('Y-m-d'),
            'date_to'   => $to->format('Y-m-d'),
            'type'      => $type,
            'category'  => $categoryParam,
        ], fn($v) => $v !== null));
    }

    /**
     * Rango y etiqueta del período elegido en la barra de filtros.
     *
     * month = 0 (o fuera de rango, si llega manipulado) significa año completo.
     *
     * @return array{0: \DateTime, 1: \DateTime, 2: string}
     */
    private function resolvePeriod(int $year, int $month): array
    {
        $monthNames = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

        if ($month < 1 || $month > 12) {
            return [new \DateTime("$year-01-01"), new \DateTime("$year-12-31"), (string) $year];
        }

        $from = new \DateTime("$year-$month-01");
        $to   = (new \DateTime("$year-$month-01"))->modify('last day of this month');

        return [$from, $to, $monthNames[$month - 1] . ' ' . $year];
    }
}
