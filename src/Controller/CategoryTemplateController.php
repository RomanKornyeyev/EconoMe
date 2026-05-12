<?php

namespace App\Controller;

use App\Entity\CategoryTemplate;
use App\Form\CategoryTemplateType;
use App\Repository\CategoryTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/settings/templates', name: 'category_template_')]
class CategoryTemplateController extends AbstractController
{
    public function __construct(
        private CategoryTemplateRepository $templateRepo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $templates = $this->templateRepo->findAllByUser($this->getUser());

        return $this->render('category_template/index.html.twig', [
            'templates' => $templates,
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request): Response
    {
        $template = new CategoryTemplate($this->getUser());
        $form = $this->createForm(CategoryTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($template);
            $this->em->flush();

            $this->addFlash('success', 'Plantilla creada.');
            return $this->redirectToRoute('category_template_index');
        }

        return $this->render('category_template/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(CategoryTemplate $template, Request $request): Response
    {
        if ($template->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(CategoryTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Plantilla actualizada.');
            return $this->redirectToRoute('category_template_index');
        }

        return $this->render('category_template/edit.html.twig', [
            'form'     => $form,
            'template' => $template,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(CategoryTemplate $template, Request $request): Response
    {
        if ($template->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete' . $template->getId(), $request->request->get('_token'))) {
            $this->em->remove($template);
            $this->em->flush();
            $this->addFlash('success', 'Plantilla eliminada.');
        }

        return $this->redirectToRoute('category_template_index');
    }
}
