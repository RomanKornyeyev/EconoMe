<?php

namespace App\Service;

use App\Entity\CategoryTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class CategoryTemplateSeeder
{
    private const TEMPLATES = [
        // Gastos (comunmente)
        ['name' => 'Alimentación', 'color' => '#4CAF50'],
        ['name' => 'Hipoteca / alquiler', 'color' => '#26A69A'],
        ['name' => 'Hogar', 'color' => '#8BC34A'],
        ['name' => 'Informática /cloud', 'color' => '#007495'],
        ['name' => 'Internet / móvil', 'color' => '#2dd4bf'],
        ['name' => 'Luz / agua / gas', 'color' => '#0000df'],
        ['name' => 'Ocio', 'color' => '#e879f9'],
        ['name' => 'Regalos', 'color' => '#7E57C2'],
        ['name' => 'Restaurantes / delivery', 'color' => '#e91e63'],
        ['name' => 'Ropa', 'color' => '#9C27B0'],
        ['name' => 'Salud', 'color' => '#F44336'],
        ['name' => 'Seguros', 'color' => '#3F51B5'],
        ['name' => 'Suscripciones', 'color' => '#00BCD4'],
        ['name' => 'Transporte', 'color' => '#2196F3'],
        ['name' => 'Vacaciones / viajes', 'color' => '#FF5722'],
        ['name' => 'Educación', 'color' => '#FF9800'],
        ['name' => 'Mascotas', 'color' => '#FFC107'],
        
        // Ingresos (comunmente)
        ['name' => 'Nómina', 'color' => '#4CAF50'],
        ['name' => 'Freelance', 'color' => '#2196F3'],
        ['name' => 'Inversiones', 'color' => '#FF9800'],

        // Mixtas
        ['name' => 'Otros', 'color' => '#9E9E9E'],
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
        foreach (self::TEMPLATES as $data) {
            $template = new CategoryTemplate($user);
            $template->setName($data['name']);
            $template->setColor($data['color']);
            $this->em->persist($template);
        }
    }
}
