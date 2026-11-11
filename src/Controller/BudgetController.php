<?php

namespace App\Controller;

use App\Budget\BudgetEditor;
use App\Budget\BudgetEvaluator;
use App\Budget\BudgetPeriod;
use App\Entity\Account;
use App\Entity\Budget;
use App\Enum\Frequency;
use App\Form\BudgetAmountType;
use App\Form\BudgetType;
use App\Repository\BudgetRepository;
use App\Service\AccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Página «Presupuestos»: lista, alta, cambios de importe, finalizar y eliminar.
 * Diseño en claude/presupuestos.md.
 *
 * Alta y edición viven solo en un modal (budget_modal_controller.js), como en
 * categorías: con XHR responden JSON {ok, body} y sin XHR vuelven a la lista con
 * un flash (ver modalResponse()).
 */
#[IsGranted('ROLE_USER')]
#[Route('/budget', name: 'budget_')]
class BudgetController extends AbstractController
{
    public function __construct(
        private AccountService $accountService,
        private BudgetRepository $budgetRepo,
        private BudgetEditor $editor,
        private BudgetEvaluator $evaluator,
        private EntityManagerInterface $em,
    ) {}

    // ── Lista ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $accounts = $this->accountService->getActiveAccountsForUser($this->getUser());
        if (empty($accounts)) {
            return $this->render('budget/index.html.twig', ['hasAccounts' => false]);
        }

        $account = $this->accountService->resolveCurrentAccount($request, $accounts);
        if (!$account) {
            throw $this->createNotFoundException('Cuenta no encontrada');
        }
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $today = new \DateTimeImmutable('today');
        $active = [];
        $finished = [];
        foreach ($this->budgetRepo->findByAccount($account) as $budget) {
            if ($budget->hasEnded($today)) {
                $finished[] = $budget;
            } else {
                $active[] = $budget;
            }
        }

        return $this->render('budget/index.html.twig', [
            'hasAccounts'    => true,
            'accounts'       => $accounts,
            'currentAccount' => $account,
            'budgets'        => $active,
            'finished'       => $finished,
            'progress'       => $this->evaluator->current($account, $active, $today),
            'today'          => $today,
        ]);
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    /**
     * Con ?from=<id> es «Crear uno igual»: el alta sale con los datos y el último
     * importe de ese presupuesto, empezando después de su fin si hace falta para
     * no solaparse.
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $account = $this->em->getRepository(Account::class)->find($request->query->getInt('account'));
        if (!$account || $account->isDeleted()) {
            $this->addFlash('error', 'Cuenta no encontrada.');
            return $this->redirectToRoute('budget_index');
        }
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        $today = new \DateTimeImmutable('today');
        $data = ['type' => Budget::TYPE_EXPENSE, 'scope' => 'category', 'frequency' => Frequency::Monthly, 'start' => 0, 'end' => null];
        $action = ['account' => $account->getId()];

        $source = $request->query->getInt('from') ? $this->budgetRepo->find($request->query->getInt('from')) : null;
        if ($source !== null && $source->getAccount() === $account) {
            $lastAmount = $source->getSchedule()->amountAt($source->lastPeriod() ?? $source->periodAt($today));
            $data = [
                'type'      => $source->getType(),
                'scope'     => $source->isAccountWide() ? 'account' : 'category',
                'category'  => $source->getCategory(),
                'frequency' => $source->getFrequency(),
                'amount'    => $lastAmount,
                'start'     => $source->lastPeriod() !== null && $source->lastPeriod()->compare($source->periodAt($today)) >= 0 ? 1 : 0,
                'end'       => null,
            ];
            $action['from'] = $source->getId();
        }

        // La frecuencia elegida decide las etiquetas de inicio y fin (ver BudgetType).
        $frequency = $request->isMethod('POST')
            ? (Frequency::tryFrom((string) ($request->request->all('budget')['frequency'] ?? '')) ?? Frequency::Monthly)
            : $data['frequency'];

        $form = $this->createForm(BudgetType::class, $data, [
            'account'   => $account,
            'currency'  => $account->getCurrency(),
            'frequency' => $frequency,
            'today'     => $today,
            'action'    => $this->generateUrl('budget_new', $action),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $values = $form->getData();
            /** @var Frequency $frequency */
            $frequency = $values['frequency'];
            $current = BudgetPeriod::containing($frequency, $today);
            $first = $current->offset((int) $values['start']);
            $last = $values['end'] === null ? null : $current->offset((int) $values['end']);
            $category = $values['scope'] === 'category' ? $values['category'] : null;

            $clash = $this->editor->findClash($account, $values['type'], $frequency, $category, $first, $last);
            if ($clash !== null) {
                $form->addError(new FormError($this->clashMessage($clash)));
            } else {
                $budget = $this->editor->create($account, $this->getUser(), $values['type'], $frequency, $category, $first, $last, $values['amount']);
                $this->em->persist($budget);
                $this->em->flush();
                $this->addFlash('success', sprintf('Presupuesto «%s» creado.', $budget->getName()));

                return $this->saved($request, $account);
            }
        }

        return $this->modalResponse($request, $form, 'budget/_form_new.html.twig', [
            'currentAccount' => $account,
            'labels'         => BudgetType::periodLabels($today),
        ], $account);
    }

    // ── Cambiar el importe ──────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Budget $budget, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $budget->getAccount());
        $today = new \DateTimeImmutable('today');

        $form = $this->amountForm($budget, $today);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ['scope' => $scope, 'period' => $key, 'amount' => $amount] = $form->getData();
            $period = $scope === BudgetAmountType::SCOPE_ALL
                ? $budget->firstPeriod()
                : BudgetPeriod::containing($budget->getFrequency(), new \DateTimeImmutable($key));
            $budget->applySchedule($this->editor->scheduleWith($budget, $scope, $period, $amount));
            $this->em->flush();
            $this->addFlash('success', sprintf('Importe de «%s» actualizado.', $budget->getName()));

            return $this->saved($request, $budget->getAccount());
        }

        return $this->modalResponse($request, $form, 'budget/_form_edit.html.twig', $this->editView($budget, $today), $budget->getAccount());
    }

    /**
     * «Qué va a pasar»: el efecto del cambio de importe antes de guardarlo. Recibe
     * el formulario de edición tal cual (budget-modal lo manda al escribir) y
     * devuelve el fragmento budget/_preview.html.twig. No guarda nada.
     */
    #[Route('/{id}/preview', name: 'preview', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function preview(Budget $budget, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $budget->getAccount());
        $today = new \DateTimeImmutable('today');

        // Solo lectura: sin CSRF, para no depender del token del envío.
        $form = $this->amountForm($budget, $today, ['csrf_protection' => false]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('budget/_preview.html.twig', ['preview' => null, 'budget' => $budget]);
        }

        ['scope' => $scope, 'period' => $key, 'amount' => $amount] = $form->getData();
        $period = $scope === BudgetAmountType::SCOPE_ALL
            ? $budget->firstPeriod()
            : BudgetPeriod::containing($budget->getFrequency(), new \DateTimeImmutable($key));

        return $this->render('budget/_preview.html.twig', [
            'preview' => $this->editor->preview($budget, $scope, $period, $amount, $today),
            'budget'  => $budget,
        ]);
    }

    /** Quitar un cambio de importe del historial (del periodo en curso o posterior). */
    #[Route('/{id}/change/{key}/remove', name: 'change_remove', methods: ['POST'], requirements: ['id' => '\d+', 'key' => '\d{4}-\d{2}-\d{2}'])]
    public function removeChange(Budget $budget, string $key, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $budget->getAccount());
        $today = new \DateTimeImmutable('today');

        if ($this->isCsrfTokenValid('budget_change' . $budget->getId(), $request->request->getString('_token'))) {
            $schedule = $budget->getSchedule();
            $change = $schedule->changeStartingAt($key);
            if ($change !== null && $change->isRemovable($budget->periodAt($today))) {
                $budget->applySchedule($schedule->without($change));
                $this->em->flush();
            }
        }

        if ($request->isXmlHttpRequest()) {
            $form = $this->amountForm($budget, $today);

            return new JsonResponse([
                'ok'   => true,
                'body' => $this->renderView('budget/_form_edit.html.twig', $this->editView($budget, $today) + ['form' => $form->createView()]),
            ]);
        }

        $this->addFlash('success', sprintf('Importe de «%s» actualizado.', $budget->getName()));

        return $this->redirectToRoute('budget_index', ['account' => $budget->getAccount()->getId()]);
    }

    // ── Finalizar y eliminar ────────────────────────────────────────────────

    #[Route('/{id}/finalize', name: 'finalize', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function finalize(Budget $budget, Request $request): Response
    {
        $account = $budget->getAccount();
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        if ($this->isCsrfTokenValid('budget_finalize' . $budget->getId(), $request->request->getString('_token'))) {
            $today = new \DateTimeImmutable('today');
            $name = $budget->getName();
            if ($this->editor->finalize($budget, $today)) {
                $this->addFlash('success', sprintf('Presupuesto «%s» finalizado: deja de contar después de %s. Su historial se conserva.', $name, $budget->periodAt($today)->labelWithArticle()));
            } else {
                $this->em->remove($budget);
                $this->addFlash('success', sprintf('Presupuesto «%s» eliminado: aún no había empezado.', $name));
            }
            $this->em->flush();
        }

        return $this->redirectToRoute('budget_index', ['account' => $account->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Budget $budget, Request $request): Response
    {
        $account = $budget->getAccount();
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        if ($this->isCsrfTokenValid('budget_delete' . $budget->getId(), $request->request->getString('_token'))) {
            $name = $budget->getName();
            $this->em->remove($budget);
            $this->em->flush();
            $this->addFlash('success', sprintf('Presupuesto «%s» eliminado.', $name));
        }

        return $this->redirectToRoute('budget_index', ['account' => $account->getId()]);
    }

    // ── Del panel del dashboard a los movimientos ───────────────────────────

    /**
     * Salto de una fila del panel a sus movimientos: el ámbito y el tipo del
     * presupuesto en las fechas que se miraban. Hace escala aquí, como el
     * drill-down del donut, para dejar un flash que diga qué se ha filtrado y
     * ofrezca la vuelta al dashboard.
     */
    #[Route('/{id}/transactions', name: 'transactions', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function transactions(Budget $budget, Request $request): Response
    {
        $account = $budget->getAccount();
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $period = $budget->periodAt(new \DateTimeImmutable('today'));
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('from')) ?: $period->start;
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('to')) ?: $period->end;

        $back = ['account' => $account->getId()];
        if ($request->query->has('year')) {
            $back += ['year' => $request->query->getInt('year'), 'month' => $request->query->getInt('month')];
        }

        $this->addFlash('info_html', sprintf(
            '<a href="%s" class="alert-link"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>'
            . ' &nbsp;&middot;&nbsp; Filtrados %s %s, del %s al %s.',
            htmlspecialchars($this->generateUrl('dashboard', $back), ENT_QUOTES, 'UTF-8'),
            $budget->isExpense() ? 'gastos' : 'ingresos',
            $budget->isAccountWide()
                ? 'de toda la cuenta'
                : 'de ' . htmlspecialchars($budget->getName(), ENT_QUOTES, 'UTF-8'),
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        ));

        return $this->redirectToRoute('transaction_index', array_filter([
            'account'   => $account->getId(),
            'date_from' => $from->format('Y-m-d'),
            'date_to'   => $to->format('Y-m-d'),
            'type'      => $budget->getType(),
            'category'  => $budget->getCategory()?->getId(),
        ], fn ($v) => $v !== null));
    }

    // ── Privado ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $options */
    private function amountForm(Budget $budget, \DateTimeImmutable $today, array $options = []): FormInterface
    {
        return $this->createForm(BudgetAmountType::class, [
            'scope'  => BudgetAmountType::SCOPE_FROM,
            'period' => BudgetAmountType::firstEditable($budget, $today)->key(),
        ], $options + [
            'budget' => $budget,
            'today'  => $today,
            'action' => $this->generateUrl('budget_edit', ['id' => $budget->getId()]),
        ]);
    }

    /**
     * Variables del modal de edición (budget/_form_edit.html.twig), menos el form.
     *
     * @return array<string, mixed>
     */
    private function editView(Budget $budget, \DateTimeImmutable $today): array
    {
        $schedule = $budget->getSchedule();
        $current = $budget->periodAt($today);

        return [
            'budget'   => $budget,
            'current'  => $current,
            'editable' => BudgetAmountType::firstEditable($budget, $today),
            'amountNow' => $schedule->amountAt(BudgetAmountType::firstEditable($budget, $today)),
            'changes'  => $schedule->changes(),
        ];
    }

    /** Guardado: el modal recarga la página (y ahí sale el flash); sin XHR, a la lista. */
    private function saved(Request $request, Account $account): Response
    {
        return $request->isXmlHttpRequest()
            ? new JsonResponse(['ok' => true])
            : $this->redirectToRoute('budget_index', ['account' => $account->getId()]);
    }

    /**
     * Formulario del modal, como CategoryController::handleForm():
     *   · XHR GET           → 200 {ok: true,  body}
     *   · XHR POST inválido → 422 {ok: false, body} con sus errores
     *   · sin XHR           → no hay página que pintar: a la lista con un flash.
     *
     * @param array<string, mixed> $vars
     */
    private function modalResponse(Request $request, FormInterface $form, string $template, array $vars, Account $account): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok'   => !$form->isSubmitted(),
                'body' => $this->renderView($template, $vars + ['form' => $form->createView()]),
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }

        if ($form->isSubmitted()) {
            $errors = array_map(fn ($error) => $error->getMessage(), iterator_to_array($form->getErrors(true)));
            $this->addFlash('error', trim('No se pudo guardar. ' . implode(' ', array_unique($errors))));
        }

        return $this->redirectToRoute('budget_index', ['account' => $account->getId()]);
    }

    /** Por qué no se puede crear: con qué presupuesto se solapa y desde cuándo se podría. */
    private function clashMessage(Budget $clash): string
    {
        $what = mb_strtolower($clash->getKindLabel()) . ($clash->isAccountWide() ? ' de toda la cuenta' : ' para ' . $clash->getName());
        $last = $clash->lastPeriod();

        return $last === null
            ? sprintf('Ya tienes %s %s. Cambia su importe con el lápiz, o finalízalo antes de crear otro.', $clash->isExpense() ? 'un' : 'una', $what)
            : sprintf('Se solapa con tu %s, que sigue hasta %s. Empieza en %s o después.', $what, $last->label(), $last->next()->label());
    }
}
