<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Service\AccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/category', name: 'category_')]
class CategoryController extends AbstractController
{
    public function __construct(
        private CategoryRepository $categoryRepo,
        private AccountService $accountService,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $accounts = $this->accountService->getActiveAccountsForUser($this->getUser());

        if (empty($accounts)) {
            return $this->redirectToRoute('account_create');
        }

        $accountId = $request->query->getInt('account', $accounts[0]->getId());
        $account = $this->em->getRepository(Account::class)->find($accountId);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $categories = $this->categoryRepo->findAllByAccount($account);

        return $this->render('category/index.html.twig', [
            'accounts'       => $accounts,
            'currentAccount' => $account,
            'categories'     => $categories,
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request): Response
    {
        $accountId = $request->query->getInt('account');
        $account = $this->em->getRepository(Account::class)->find($accountId);

        if (!$account) {
            $this->addFlash('error', 'Cuenta no encontrada.');
            return $this->redirectToRoute('category_index');
        }

        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        $category = new Category($account);
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($category);
            $this->em->flush();

            $this->addFlash('success', 'Categoría creada.');
            return $this->redirectToRoute('category_index', ['account' => $account->getId()]);
        }

        return $this->render('category/create.html.twig', [
            'form'    => $form,
            'account' => $account,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(Category $category, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $category->getAccount());

        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Categoría actualizada.');
            return $this->redirectToRoute('category_index', ['account' => $category->getAccount()->getId()]);
        }

        return $this->render('category/edit.html.twig', [
            'form'     => $form,
            'category' => $category,
        ]);
    }

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
}
