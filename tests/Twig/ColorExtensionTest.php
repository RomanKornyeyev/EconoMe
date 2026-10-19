<?php

namespace App\Tests\Twig;

use App\Twig\ColorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Color del texto del avatar de categoría sobre el color que elige el usuario:
 * oscuro sobre fondos claros (toda la paleta) y blanco sobre oscuros.
 */
class ColorExtensionTest extends TestCase
{
    private const DARK = '#212529';
    private const LIGHT = '#fff';

    #[DataProvider('colors')]
    public function testContrastText(?string $background, string $expected): void
    {
        $this->assertSame($expected, (new ColorExtension())->contrastText($background));
    }

    public static function colors(): iterable
    {
        // Paleta del selector (CategoryType::COLOR_PALETTE): todos claros
        yield 'gris paleta' => ['#94a3b8', self::DARK];
        yield 'rojo paleta' => ['#f87171', self::DARK];
        yield 'índigo paleta' => ['#818cf8', self::DARK];

        // Colores personalizados oscuros
        yield 'negro' => ['#000000', self::LIGHT];
        yield 'morado oscuro' => ['#5e00b3', self::LIGHT];

        yield 'blanco' => ['#ffffff', self::DARK];
        yield 'mayúsculas' => ['#F87171', self::DARK];
        // Colores del seeder antiguo (Material 500): más oscuros que la paleta
        yield 'rosa material' => ['#E91E63', self::LIGHT];
        yield 'formato corto' => ['#fff', self::DARK];
        yield 'formato corto oscuro' => ['#003', self::LIGHT];

        // Sin color o con basura: el color de texto del tema
        yield 'null' => [null, 'var(--bs-body-color)'];
        yield 'vacío' => ['', 'var(--bs-body-color)'];
        yield 'no es un color' => ['red', 'var(--bs-body-color)'];
    }
}
