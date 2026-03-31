<?php

namespace App\Controller;

use App\Form\UserSettingsType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserSettingsController extends AbstractController
{
    #[Route('/settings', name: 'user_settings')]
    public function edit(Request $request, EntityManagerInterface $em): Response
    {
        $settings = $this->getUser()->getSettings();

        $form = $this->createForm(UserSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Configuración actualizada.');
            return $this->redirectToRoute('user_settings');
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
        ]);
    }
}
