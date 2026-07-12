<?php

namespace App\Controller;

use App\Entity\UserSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class TourController extends AbstractController
{
    /** Tours de onboarding existentes. Añadir aquí los nuevos. */
    public const TOURS = ['dashboard', 'transactions', 'categories', 'accounts'];

    #[Route('/tour/{name}/complete', name: 'tour_complete', methods: ['POST'])]
    public function complete(string $name, Request $request, EntityManagerInterface $em): Response
    {
        if (!in_array($name, self::TOURS, true)) {
            return new JsonResponse(['error' => 'Tour desconocido'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('tour', $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'Token inválido'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $settings = $user->getSettings();

        // Usuarios antiguos sin settings creados en el registro
        if ($settings === null) {
            $settings = new UserSettings($user);
            $user->setSettings($settings);
            $em->persist($settings);
        }

        $settings->markTourCompleted($name);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
