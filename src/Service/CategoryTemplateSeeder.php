<?php

namespace App\Service;

use App\Entity\CategoryTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class CategoryTemplateSeeder
{
    private const EXPENSE_TEMPLATES = [
        ['name' => 'Alimentación', 'icon' => 'cart', 'color' => '#4CAF50'],
        ['name' => 'Transporte', 'icon' => 'car', 'color' => '#2196F3'],
        ['name' => 'Vivienda', 'icon' => 'home', 'color' => '#795548'],
        ['name' => 'Ocio', 'icon' => 'gamepad', 'color' => '#E91E63'],
        ['name' => 'Salud', 'icon' => 'heart', 'color' => '#F44336'],
        ['name' => 'Educación', 'icon' => 'book', 'color' => '#FF9800'],
        ['name' => 'Ropa', 'icon' => 'shirt', 'color' => '#9C27B0'],
        ['name' => 'Suscripciones', 'icon' => 'refresh', 'color' => '#00BCD4'],
        ['name' => 'Otros', 'icon' => 'dots', 'color' => '#607D8B'],
    ];

    private const INCOME_TEMPLATES = [
        ['name' => 'Nómina', 'icon' => 'briefcase', 'color' => '#4CAF50'],
        ['name' => 'Freelance', 'icon' => 'laptop', 'color' => '#2196F3'],
        ['name' => 'Inversiones', 'icon' => 'trending-up', 'color' => '#FF9800'],
        ['name' => 'Otros ingresos', 'icon' => 'dots', 'color' => '#607D8B'],
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Crea las plantillas por defecto para un nuevo usuario.
     * Llamar al registrar. No hace flush.
     */
    public function seedForUser(User $user): void
    {
        foreach (self::EXPENSE_TEMPLATES as $data) {
            $template = new CategoryTemplate($user);
            $template->setName($data['name']);
            $template->setIcon($data['icon']);
            $template->setColor($data['color']);
            $template->setType(CategoryTemplate::TYPE_EXPENSE);
            $this->em->persist($template);
        }

        foreach (self::INCOME_TEMPLATES as $data) {
            $template = new CategoryTemplate($user);
            $template->setName($data['name']);
            $template->setIcon($data['icon']);
            $template->setColor($data['color']);
            $template->setType(CategoryTemplate::TYPE_INCOME);
            $this->em->persist($template);
        }
    }
}
