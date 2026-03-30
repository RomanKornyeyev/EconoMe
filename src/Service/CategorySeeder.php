<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class CategorySeeder
{
    private const EXPENSE_CATEGORIES = [
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

    private const INCOME_CATEGORIES = [
        ['name' => 'Nómina', 'icon' => 'briefcase', 'color' => '#4CAF50'],
        ['name' => 'Freelance', 'icon' => 'laptop', 'color' => '#2196F3'],
        ['name' => 'Inversiones', 'icon' => 'trending-up', 'color' => '#FF9800'],
        ['name' => 'Otros ingresos', 'icon' => 'dots', 'color' => '#607D8B'],
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function seedForUser(User $user): void
    {
        foreach (self::EXPENSE_CATEGORIES as $data) {
            $category = new Category($user);
            $category->setName($data['name']);
            $category->setIcon($data['icon']);
            $category->setColor($data['color']);
            $category->setType(Category::TYPE_EXPENSE);
            $this->em->persist($category);
        }

        foreach (self::INCOME_CATEGORIES as $data) {
            $category = new Category($user);
            $category->setName($data['name']);
            $category->setIcon($data['icon']);
            $category->setColor($data['color']);
            $category->setType(Category::TYPE_INCOME);
            $this->em->persist($category);
        }

        // No flush aquí — se hace en el servicio/controlador que llame a seedForUser
    }
}
