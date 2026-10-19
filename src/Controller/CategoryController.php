<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\CategoryTemplate;
use App\Form\CategoryTemplateType;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Repository\CategoryTemplateRepository;
use App\Repository\TransactionRepository;
use App\Service\AccountService;
use App\Service\CategoryUsage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Categorías de cuenta y plantillas de categorías del usuario.
 *
 * Crear y editar se hacen solo en un modal (category_modal_controller.js): las
 * acciones de formulario responden JSON al modal (ver handleForm()) y no tienen
 * vista de página completa propia; la antigua category/edit.html.twig se borró.
 */
#[IsGranted('ROLE_USER')]
#[Route('/category', name: 'category_')]
class CategoryController extends AbstractController
{
    /** Movimientos que enseña el resumen de una categoría. */
    private const SUMMARY_LATEST = 3;

    public function __construct(
        private CategoryRepository $categoryRepo,
        private CategoryTemplateRepository $templateRepo,
        private AccountService $accountService,
        private CategoryUsage $categoryUsage,
        private EntityManagerInterface $em,
    ) {}

    // ── Index ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $user     = $this->getUser();
        $accounts = $this->accountService->getActiveAccountsForUser($user);
        $isTemplate = $request->query->getBoolean('template');

        // Vista de plantillas: cuando se pide explícitamente o cuando el usuario
        // aún no tiene ninguna cuenta (al menos puede ver/editar su plantilla en
        // vez de ser forzado a crear una cuenta).
        if ($isTemplate || empty($accounts)) {
            $templates = $this->templateRepo->findAllByUser($user);

            return $this->render('category/index.html.twig', [
                'accounts'       => $accounts,
                'currentAccount' => null,
                'categories'     => [],
                'usage'          => [],
                'templates'      => $templates,
                'isTemplateView' => true,
            ]);
        }

        $account = $this->accountService->resolveCurrentAccount($request, $accounts);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $categories = $this->categoryRepo->findAllByAccount($account);

        return $this->render('category/index.html.twig', [
            'accounts'       => $accounts,
            'currentAccount' => $account,
            'categories'     => $categories,
            // Nº de movimientos por tipo: columnas «Se usa en» y «Movimientos»
            'usage'          => $this->categoryUsage->countsByCategory($account),
            'templates'      => [],
            'isTemplateView' => false,
        ]);
    }

    // ── Resumen (modal «Ver») ────────────────────────────────────────────────

    #[Route('/{id}/summary', name: 'summary', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function summary(Category $category, TransactionRepository $transactionRepo): Response
    {
        $account = $category->getAccount();
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $monthTotals = $transactionRepo->totalsByTypeForCategory(
            $category,
            new \DateTime('first day of this month midnight'),
            new \DateTime('last day of this month midnight'),
        );
        $counts = $this->categoryUsage->countsByCategory($account)[$category->getId()] ?? [];

        return $this->render('category/_summary.html.twig', [
            'item'        => $category,
            'isTemplate'  => false,
            'counts'      => $counts,
            'totalCount'  => array_sum($counts),
            'monthTotals' => $monthTotals,
            'monthCount'  => array_sum(array_column($monthTotals, 'count')),
            'latest'      => $transactionRepo->findLatestByCategory($category, self::SUMMARY_LATEST),
            'canEdit'     => $this->isGranted('ACCOUNT_EDIT', $account),
        ]);
    }

    /**
     * Resumen de una plantilla. Mismo modal y mismo flujo que una categoría, para
     * no liar al usuario; como una plantilla no tiene movimientos, las cifras
     * salen vacías y el modal explica por qué.
     */
    #[Route('/template/{id}/summary', name: 'template_summary', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function templateSummary(CategoryTemplate $template): Response
    {
        if ($template->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('category/_summary.html.twig', [
            'item'        => $template,
            'isTemplate'  => true,
            'counts'      => [],
            'totalCount'  => 0,
            'monthTotals' => [],
            'monthCount'  => 0,
            'latest'      => [],
            'canEdit'     => true,
        ]);
    }

    /**
     * Salto del resumen («Ver todos los movimientos») al listado filtrado por la
     * categoría.
     *
     * Hace escala en el servidor, como el drill-down del dashboard, para dejar
     * un flash que diga qué se ha filtrado y ofrezca la vuelta a categorías. Al
     * viajar por sesión y no por la URL, el listado conserva sus enlaces limpios
     * y el aviso no reaparece al ordenar o paginar.
     */
    #[Route('/{id}/transactions', name: 'transactions', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function transactions(Category $category): Response
    {
        $account = $category->getAccount();
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $this->addFlash('info_html', sprintf(
            '<a href="%s" class="alert-link"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>'
            . ' &nbsp;&middot;&nbsp; Filtrados los movimientos con categoría %s.',
            htmlspecialchars($this->generateUrl('category_index', ['account' => $account->getId()]), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($category->getName(), ENT_QUOTES, 'UTF-8'),
        ));

        return $this->redirectToRoute('transaction_index', [
            'account'  => $account->getId(),
            'category' => $category->getId(),
        ]);
    }

    // ── Crear plantilla ──────────────────────────────────────────────────────

    #[Route('/template/create', name: 'template_create')]
    public function templateCreate(Request $request): Response
    {
        $template = new CategoryTemplate($this->getUser());
        $form = $this->createForm(CategoryTemplateType::class, $template, [
            'action' => $this->generateUrl('category_template_create'),
        ]);

        return $this->handleForm($request, $form, $this->formView(true, true),
            function () use ($template) {
                $this->em->persist($template);
                $this->em->flush();
            },
            'Plantilla creada.',
            $this->generateUrl('category_index', ['template' => 'true']),
        );
    }

    // ── Crear categoría ──────────────────────────────────────────────────────

    #[Route('/create', name: 'create')]
    public function create(Request $request): Response
    {
        $accountId = $request->query->getInt('account');
        $account   = $this->em->getRepository(Account::class)->find($accountId);

        if (!$account) {
            $this->addFlash('error', 'Cuenta no encontrada.');
            return $this->redirectToRoute('category_index');
        }

        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        $category = new Category($account);
        $form = $this->createForm(CategoryType::class, $category, [
            'action' => $this->generateUrl('category_create', ['account' => $account->getId()]),
        ]);

        return $this->handleForm($request, $form, $this->formView(false, true),
            function () use ($category) {
                $this->em->persist($category);
                $this->em->flush();
            },
            'Categoría creada.',
            $this->generateUrl('category_index', ['account' => $account->getId()]),
        );
    }

    // ── Editar categoría ─────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(Category $category, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $category->getAccount());

        $back = $request->query->getBoolean('back');
        $form = $this->createForm(CategoryType::class, $category, [
            'action' => $this->generateUrl('category_edit', ['id' => $category->getId()] + ($back ? ['back' => 1] : [])),
        ]);

        $view = $this->formView(false, false,
            $back ? $this->generateUrl('category_summary', ['id' => $category->getId()]) : null);

        return $this->handleForm($request, $form, $view,
            fn () => $this->em->flush(),
            'Categoría actualizada.',
            $this->generateUrl('category_index', ['account' => $category->getAccount()->getId()]),
        );
    }

    // ── Eliminar categoría ───────────────────────────────────────────────────

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Category $category, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $category->getAccount());
        $accountId = $category->getAccount()->getId();

        if ($this->isCsrfTokenValid('delete' . $category->getId(), $request->request->get('_token'))) {
            $this->em->remove($category);
            $this->em->flush();
            $this->addFlash('success', 'Categoría eliminada.');
        }

        return $this->redirectToRoute('category_index', ['account' => $accountId]);
    }

    // ── Editar plantilla ─────────────────────────────────────────────────────

    #[Route('/template/{id}/edit', name: 'template_edit', requirements: ['id' => '\d+'])]
    public function templateEdit(CategoryTemplate $template, Request $request): Response
    {
        if ($template->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $back = $request->query->getBoolean('back');
        $form = $this->createForm(CategoryTemplateType::class, $template, [
            'action' => $this->generateUrl('category_template_edit', ['id' => $template->getId()] + ($back ? ['back' => 1] : [])),
        ]);

        $view = $this->formView(true, false,
            $back ? $this->generateUrl('category_template_summary', ['id' => $template->getId()]) : null);

        return $this->handleForm($request, $form, $view,
            fn () => $this->em->flush(),
            'Plantilla actualizada.',
            $this->generateUrl('category_index', ['template' => 'true']),
        );
    }

    // ── Eliminar plantilla ───────────────────────────────────────────────────

    #[Route('/template/{id}/delete', name: 'template_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function templateDelete(CategoryTemplate $template, Request $request): Response
    {
        if ($template->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete' . $template->getId(), $request->request->get('_token'))) {
            $this->em->remove($template);
            $this->em->flush();
            $this->addFlash('success', 'Plantilla eliminada.');
        }

        return $this->redirectToRoute('category_index', ['template' => 'true']);
    }

    // ── Privado ──────────────────────────────────────────────────────────────

    /**
     * Flujo común de crear/editar categoría o plantilla.
     *
     * Con XHR (el modal) responde JSON:
     *   · GET            → 200 {ok: true,  body}: el formulario a pintar en el modal;
     *   · POST inválido  → 422 {ok: false, body}: el formulario con sus errores;
     *   · POST válido    → 200 {ok: true}: sin body; el cliente recarga la página
     *     y ahí aparece el flash, igual que tras un envío normal.
     *
     * Sin XHR no hay página que pintar: el formulario solo vive en el modal. Llega
     * así una URL abierta a mano (un marcador antiguo de la vista que se borró) o
     * el envío nativo con que el modal se rescata si su fetch falla. Se guarda si
     * es válido y, en cualquier caso, se vuelve al listado con un flash.
     *
     * @param array<string, mixed> $view  variables de category/_form.html.twig (ver formView())
     * @param callable(): void     $save  persiste el cambio (persist/flush)
     */
    private function handleForm(
        Request $request,
        FormInterface $form,
        array $view,
        callable $save,
        string $flash,
        string $backUrl,
    ): Response {
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $save();
            $this->addFlash('success', $flash);

            return $request->isXmlHttpRequest()
                ? new JsonResponse(['ok' => true])
                : $this->redirect($backUrl);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok'   => !$form->isSubmitted(),
                'body' => $this->renderView('category/_form.html.twig', $view + ['form' => $form->createView()]),
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }

        if ($form->isSubmitted()) {
            $errors = array_map(fn ($error) => $error->getMessage(), iterator_to_array($form->getErrors(true)));
            $this->addFlash('error', trim('No se pudo guardar. ' . implode(' ', array_unique($errors))));
        }

        return $this->redirect($backUrl);
    }

    /**
     * Variables del formulario del modal (category/_form.html.twig).
     *
     * @param ?string $backUrl resumen al que vuelve «Volver»: solo al editar desde
     *                         el resumen (?back=1); si no, el modal se cierra
     *
     * @return array<string, mixed>
     */
    private function formView(bool $isTemplate, bool $isNew, ?string $backUrl = null): array
    {
        if ($isTemplate) {
            $heading = $isNew ? 'Nueva categoría (mi plantilla)' : 'Editar plantilla';
        } else {
            $heading = $isNew ? 'Nueva categoría' : 'Editar categoría';
        }

        return [
            'heading' => $heading,
            'isNew'   => $isNew,
            'backUrl' => $backUrl,
        ];
    }
}
