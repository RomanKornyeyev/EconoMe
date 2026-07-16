<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChangelogController extends AbstractController
{
    #[Route('/novedades', name: 'app_changelog')]
    public function index(): Response
    {
        $path = $this->getParameter('kernel.project_dir') . '/data/changelog.json';
        $releases = is_file($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];

        // Más reciente primero, independientemente del orden en el JSON
        usort($releases, fn (array $a, array $b) => version_compare(
            ltrim($b['version'], 'v'),
            ltrim($a['version'], 'v')
        ));

        // Dentro de cada versión: primero lo nuevo, luego mejoras, luego arreglos
        $typeOrder = ['feat' => 0, 'improvement' => 1, 'fix' => 2];
        foreach ($releases as &$release) {
            usort(
                $release['changes'],
                fn (array $a, array $b) => ($typeOrder[$a['type']] ?? 99) <=> ($typeOrder[$b['type']] ?? 99)
            );
        }
        unset($release);

        return $this->render('changelog/index.html.twig', [
            'releases' => $releases,
        ]);
    }
}
