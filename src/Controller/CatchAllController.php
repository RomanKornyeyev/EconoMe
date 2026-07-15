<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Ruta comodín con prioridad mínima: captura cualquier URL que no case con
 * ninguna ruta real y lanza el 404 DESPUÉS de que el firewall haya cargado
 * la sesión, para que la página de error muestre al usuario logueado.
 */
class CatchAllController extends AbstractController
{
    #[Route('/{path}', name: 'app_catch_all', requirements: ['path' => '.+'], priority: -1000)]
    public function __invoke(string $path, RouterInterface $router): Response
    {
        // El router ya no aplica su redirección automática de barra final
        // (esta ruta casa todo antes), así que la reproducimos aquí
        if (str_ends_with($path, '/')) {
            $target = '/' . rtrim($path, '/');
            try {
                $match = $router->match($target);
                if (($match['_route'] ?? null) !== 'app_catch_all') {
                    return $this->redirect($target, Response::HTTP_MOVED_PERMANENTLY);
                }
            } catch (ExceptionInterface) {
            }
        }

        throw $this->createNotFoundException();
    }
}
