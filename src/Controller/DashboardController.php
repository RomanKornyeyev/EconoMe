<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Repository\TransactionRepository;
use App\Repository\RecurringTransactionRepository;
use App\Service\AccountService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard')]
    public function index(
        Request $request,
        AccountService $accountService,
        TransactionRepository $transactionRepo,
        RecurringTransactionRepository $recurringRepo,
        PaginatorInterface $paginator,
    ): Response {
        $user = $this->getUser();

        $accounts = $accountService->getActiveAccountsForUser($user);

        if (empty($accounts)) {
            return $this->render('dashboard/index.html.twig', [
                'hasAccounts' => false,
            ]);
        }

        $accountId = $request->query->getInt('account', $accounts[0]->getId());
        $account = $this->findAccountOrFail($accounts, $accountId);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $year  = $request->query->getInt('year', (int)date('Y'));
        $month = $request->query->getInt('month', (int)date('m'));

        // month=0 → modo año completo
        $yearOnly = ($month === 0);
        if ($yearOnly) {
            $from = new \DateTime("$year-01-01");
            $to   = new \DateTime("$year-12-31");
        } else {
            $from = new \DateTime("$year-$month-01");
            $to   = (clone $from)->modify('last day of this month');
        }

        $monthNames = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $periodLabel = $yearOnly ? (string)$year : $monthNames[$month - 1] . ' ' . $year;

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

        $pagination = $paginator->paginate(
            $transactionRepo->findByFiltersQuery($account, $year, $yearOnly ? null : $month),
            $request->query->getInt('page', 1),
            10
        );

        $transaction = new Transaction($account, $user);
        $transactionForm = $this->createForm(TransactionType::class, $transaction, [
            'currency' => $account->getCurrency(),
            'action'   => $this->generateUrl('transaction_create', ['account' => $account->getId()]),
        ]);

        return $this->render('dashboard/index.html.twig', [
            'hasAccounts'        => true,
            'accounts'           => $accounts,
            'currentAccount'     => $account,
            'balance'            => $balance,
            'periodIncome'       => $periodIncome,
            'periodExpense'      => $periodExpense,
            'activeRecurrings'   => $activeRecurrings,
            'transactions'       => $pagination,
            'expensesByCategory' => $expensesByCategory,
            'yearlyTotals'       => $yearlyTotals,
            'year'               => $year,
            'month'              => $month,
            'periodLabel'        => $periodLabel,
            'availableYears'     => $availableYears,
            'availableMonths'    => $availableMonths,
            'categoryType'       => $categoryType,
            'transactionForm'    => $transactionForm,
        ]);
    }

    private function findAccountOrFail(array $accounts, int $id): Account
    {
        foreach ($accounts as $account) {
            if ($account->getId() === $id) {
                return $account;
            }
        }
        throw $this->createNotFoundException('Cuenta no encontrada');
    }
}
