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
 * Crear y editar se hacen en un modal (category_modal_controller.js): con XHR,
 * las acciones de formulario responden JSON en vez de HTML (ver handleForm()).
 * La vista de página completa category/edit.html.twig queda EN DESUSO, no
 * borrada: cubre las URLs guardadas, el uso sin JavaScript y el fallback si el
 * AJAX falla, porque los «Editar» son <a href> de verdad que el modal intercepta.
 * Al tocar el formulario hay que mirar las dos ramas.
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
            'category'    => $category,
            'counts'      => $counts,
            'totalCount'  => array_sum($counts),
            'monthTotals' => $monthTotals,
            'monthCount'  => array_sum(array_column($monthTotals, 'count')),
            'latest'      => $transactionRepo->findLatestByCategory($category, self::SUMMARY_LATEST),
            'canEdit'     => $this->isGranted('ACCOUNT_EDIT', $account),
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

        return $this->handleForm($request, $form, $this->formView(true, true, $template),
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

        return $this->handleForm($request, $form, $this->formView(false, true, $category),
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

        $form = $this->createForm(CategoryType::class, $category, [
            'action' => $this->generateUrl('category_edit', ['id' => $category->getId()]),
        ]);

        return $this->handleForm($request, $form, $this->formView(false, false, $category),
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

        $form = $this->createForm(CategoryTemplateType::class, $template, [
            'action' => $this->generateUrl('category_template_edit', ['id' => $template->getId()]),
        ]);

        return $this->handleForm($request, $form, $this->formView(true, false, $template),
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
     * Flujo común de crear/editar categoría o plantilla, para el modal y para la
     * vista de página completa (en desuso).
     *
     * Con XHR (modal) responde JSON:
     *   · GET            → 200 {ok: true,  body}: el formulario a pintar en el modal;
     *   · POST inválido  → 422 {ok: false, body}: el formulario con sus errores;
     *   · POST válido    → 200 {ok: true}: sin body; el cliente recarga la página
     *     y ahí aparece el flash, igual que tras un envío normal.
     * Sin XHR redirige o pinta category/edit.html.twig, como siempre.
     *
     * @param array<string, mixed> $view  variables de plantilla (ver formView())
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

        return $this->render('category/edit.html.twig', $view + ['form' => $form]);
    }

    /**
     * Variables que comparten el modal (category/_form.html.twig) y la vista de
     * página completa (category/edit.html.twig).
     *
     * @return array<string, mixed>
     */
    private function formView(bool $isTemplate, bool $isNew, Category|CategoryTemplate $entity): array
    {
        if ($isTemplate) {
            $heading = $isNew ? 'Nueva categoría (mi plantilla)' : 'Editar plantilla';
        } else {
            $heading = $isNew ? 'Nueva categoría' : 'Editar categoría';
        }

        return [
            'heading'        => $heading,
            // Nombre de antes del envío: si el nuevo no valida, la cabecera no
            // debe enseñar el texto a medio corregir.
            'subtitle'       => $isNew ? null : $entity->getName(),
            'category'       => $isTemplate ? null : $entity,
            'template'       => $isTemplate ? $entity : null,
            'isTemplateView' => $isTemplate,
            'isNew'          => $isNew,
        ];
    }
}
