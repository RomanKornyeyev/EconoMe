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
use App\Service\TransactionDraftFactory;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/transaction', name: 'transaction_')]
class TransactionController extends AbstractController
{
    /** Listado completo: se asume que se viene a revisar movimientos en bloque. */
    private const PER_PAGE_DEFAULT = 25;

    /** Nº de altas de la tanda en curso del modal. Ver {@see flashModalSave()}. */
    private const FLASH_BATCH_KEY = 'tx.flash_batch';

    public function __construct(
        private AccountService $accountService,
        private TransactionRepository $transactionRepo,
        private CategoryRepository $categoryRepo,
        private EntityManagerInterface $em,
        private TransactionDraftFactory $draftFactory,
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

        // Modal de alta rápida: es esta la página donde uno se sienta a meter un
        // extracto entero, así que no tiene sentido mandarle al formulario de
        // página completa. Solo se construye si puede escribir en la cuenta.
        $transactionForm = null;
        if ($this->isGranted('ACCOUNT_EDIT', $account)) {
            $transactionForm = $this->createForm(
                TransactionType::class,
                $this->draftFactory->create($account, $user),
                [
                    'currency' => $account->getCurrency(),
                    'account'  => $account,
                    'suggest'  => true, // el modal siempre crea: sugerir categoría
                    'action'   => $this->generateUrl('transaction_create', ['account' => $account->getId()]),
                ]
            );
        }

        return $this->render('transaction/index.html.twig', [
            'accounts'        => $accounts,
            'currentAccount'  => $account,
            'pagination'      => $pagination,
            'categories'      => $categories,
            'transactionForm' => $transactionForm,
            'redirectUrl'     => $request->getRequestUri(),
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

    /**
     * Alta y edición de un movimiento.
     *
     * Sirve dos clientes a la vez, según la petición sea XHR o no:
     *
     *  · XHR — es el flujo normal desde v1.4.0. El modal pide aquí el formulario
     *    (GET) y envía aquí el guardado (POST), y siempre se responde JSON.
     *  · Navegación normal — renderiza `transaction/edit.html.twig`, la vista de
     *    página completa. **Ya nada de la aplicación enlaza a ella**: el listado y
     *    el dashboard abren el modal. Se mantiene viva y funcional a propósito,
     *    para no romper URLs guardadas ni el uso sin JavaScript, pero cualquier
     *    cambio de comportamiento hay que llevarlo también a la rama XHR, que es
     *    la que ve el usuario. Ver README.dev.md (v1.4.0).
     */
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
            $transaction = $this->draftFactory->create($account, $this->getUser());
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

        // El modal pide el formulario de edición ya renderizado: se le devuelve
        // solo el cuerpo, más lo que necesita para reetiquetarse (título, cuenta
        // y a dónde enviar). El <form> del modal, con su _token, no se toca.
        if (!$isNew && !$form->isSubmitted() && $request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok'      => true,
                'action'  => $this->generateUrl('transaction_edit', ['id' => $transaction->getId()]),
                'account' => $account->getName(),
                'body'    => $this->renderView('transaction/_form_transaction.html.twig', [
                    'form'    => $form->createView(),
                    'suggest' => false,
                ]),
            ]);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($transaction);
            }
            $this->em->flush();

            if ($isNew) {
                // La siguiente alta en esta cuenta arrancará en esta fecha
                $this->draftFactory->remember($transaction);
            }

            // Desde el modal no hay redirección que dar: se queda abierto (alta
            // encadenada) o lo cierra el propio cliente (edición). Solo se devuelve
            // la confirmación que pintar en la franja.
            if ($request->isXmlHttpRequest()) {
                $this->flashModalSave($request, $isNew);

                return new JsonResponse([
                    'ok'   => true,
                    'item' => $this->renderView('transaction/_added_item.html.twig', [
                        'tx'      => $transaction,
                        'updated' => !$isNew,
                    ]),
                ]);
            }

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

        // Envío con errores desde el modal: se devuelve solo el cuerpo del
        // formulario. Deliberadamente NO se toca el form_start/form_end del modal,
        // para que el _token que vive ahí sobreviva y el reintento siga siendo
        // válido (el id del token es el nombre del formulario, `transaction`, así
        // que el mismo sirve para alta y para edición).
        if ($form->isSubmitted() && $request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok'   => false,
                'body' => $this->renderView('transaction/_form_transaction.html.twig', [
                    'form'    => $form->createView(),
                    'suggest' => $isNew,
                ]),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('transaction/edit.html.twig', [
            'form'        => $form,
            'transaction' => $transaction,
            'account'     => $account,
            'isNew'       => $isNew,
        ]);
    }

    /**
     * Deja preparado el aviso que verá el usuario cuando el modal se cierre.
     *
     * El modal guarda por AJAX, así que no hay respuesta que pintar: el flash se
     * queda en la sesión y lo muestra la recarga que el propio modal dispara al
     * cerrarse. Sin esto, meter un movimiento suelto no daba ningún acuse de
     * recibo en la página — la franja del modal se va con él.
     *
     * Se usa `set()` y no `addFlash()` porque este último acumula: una tanda de
     * cinco altas dejaría cinco alertas apiladas. Reemplazando, siempre hay una.
     *
     * Y el propio flash sin consumir hace de marca de tanda: si al guardar sigue
     * ahí, es que la página no se ha recargado y seguimos en la misma tanda, así
     * que el contador suma; si no está, la tanda empieza de cero. Se limpia solo,
     * porque pintar la página consume el flash.
     */
    private function flashModalSave(Request $request, bool $isNew): void
    {
        // hasSession() antes de getSession(): este último lanza excepción si no la
        // hay, y el aviso nunca debe poder tumbar un guardado que ya salió bien.
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $bag = $session->getFlashBag();

        if (!$isNew) {
            // Editar cierra el modal de inmediato: no hay tanda que contar.
            $session->remove(self::FLASH_BATCH_KEY);
            $bag->set('success', 'Movimiento actualizado.');

            return;
        }

        $count = $bag->peek('success') !== []
            ? $session->get(self::FLASH_BATCH_KEY, 0) + 1
            : 1;
        $session->set(self::FLASH_BATCH_KEY, $count);

        $bag->set('success', $count === 1
            ? '1 movimiento añadido.'
            : sprintf('%d movimientos añadidos.', $count));
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

    /**
     * Asigna una categoría (o la quita) a los movimientos seleccionados.
     *
     * Es la contrapartida del alta rápida: permite meter la tanda sin pararse a
     * categorizar y ordenarlo después en bloque, filtrando por «Sin categoría».
     */
    #[Route('/bulk-categorize', name: 'bulk_categorize', methods: ['POST'])]
    public function bulkCategorize(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_categorize', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $ids = $request->request->all('ids');
        $categoryId = (string) $request->request->get('category', '');
        $category = $categoryId !== '' ? $this->categoryRepo->find((int) $categoryId) : null;

        $updated = 0;
        $mismatched = 0;

        foreach ($ids as $id) {
            $transaction = $this->transactionRepo->find((int) $id);
            if (!$transaction || !$this->isGranted('ACCOUNT_EDIT', $transaction->getAccount())) {
                continue;
            }

            if ($category !== null) {
                // Categoría de otra cuenta: solo llega con la petición manipulada,
                // porque el desplegable únicamente ofrece las de la cuenta actual.
                if ($category->getAccount()->getId() !== $transaction->getAccount()->getId()) {
                    continue;
                }
                // Las categorías están tipadas: poner una de gasto a un ingreso
                // dejaría el dato incoherente y descuadraría los gráficos. Se
                // omiten y se dicen, que es más honesto que colarlas en silencio.
                if ($category->getType() !== $transaction->getType()) {
                    $mismatched++;
                    continue;
                }
            }

            $transaction->setCategory($category);
            $updated++;
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        $this->addFlash(
            $updated > 0 ? 'success' : 'error',
            $this->bulkCategorizeMessage($updated, $mismatched)
        );

        $redirectUrl = $request->request->get('_redirect_url');
        if ($redirectUrl && str_starts_with($redirectUrl, '/')) {
            return $this->redirect($redirectUrl);
        }

        return $this->redirectToRoute('transaction_index');
    }

    private function bulkCategorizeMessage(int $updated, int $mismatched): string
    {
        if ($updated === 0) {
            return $mismatched > 0
                ? 'Ningún movimiento actualizado: esa categoría no corresponde al tipo de los seleccionados.'
                : 'Ningún movimiento actualizado.';
        }

        $done = $updated === 1 ? '1 movimiento actualizado' : "$updated movimientos actualizados";

        if ($mismatched === 0) {
            return $done . '.';
        }

        return $mismatched === 1
            ? "$done. 1 omitido por no coincidir el tipo."
            : "$done. $mismatched omitidos por no coincidir el tipo.";
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
