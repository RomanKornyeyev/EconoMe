<?php

namespace App\Service;

use App\Entity\CategoryTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class CategoryTemplateSeeder
{
    private const EXPENSE_TEMPLATES = [
        ['name' => 'Alquiler / hipoteca', 'color' => '#795548'],
        ['name' => 'Seguros', 'color' => '#3F51B5'],
        ['name' => 'Hogar', 'color' => '#8BC34A'],
        ['name' => 'Alimentación', 'color' => '#4CAF50'],
        ['name' => 'Transporte', 'color' => '#2196F3'],
        ['name' => 'Ropa', 'color' => '#9C27B0'],
        
        ['name' => 'Restaurantes / Ocio', 'color' => '#E91E63'],
        ['name' => 'Suscripciones', 'color' => '#00BCD4'],
        ['name' => 'Viajes', 'color' => '#FF5722'],

        ['name' => 'Salud', 'color' => '#F44336'],
        ['name' => 'Educación', 'color' => '#FF9800'],
        ['name' => 'Mascotas', 'color' => '#FFC107'],
        ['name' => 'Regalos', 'color' => '#9E9E9E'],
        
        ['name' => 'Otros', 'color' => '#607D8B'],
    ];

    private const INCOME_TEMPLATES = [
        ['name' => 'Nómina', 'color' => '#4CAF50'],
        ['name' => 'Freelance', 'color' => '#2196F3'],
        ['name' => 'Inversiones', 'color' => '#FF9800'],
        ['name' => 'Otros', 'color' => '#607D8B'],
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
            $template->setColor($data['color']);
            $template->setType(CategoryTemplate::TYPE_EXPENSE);
            $this->em->persist($template);
        }

        foreach (self::INCOME_TEMPLATES as $data) {
            $template = new CategoryTemplate($user);
            $template->setName($data['name']);
            $template->setColor($data['color']);
            $template->setType(CategoryTemplate::TYPE_INCOME);
            $this->em->persist($template);
        }
    }
}
