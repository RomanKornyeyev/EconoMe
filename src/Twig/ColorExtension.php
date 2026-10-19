<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class ColorExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('contrast_text', $this->contrastText(...)),
        ];
    }

    /**
     * Color de texto legible sobre un fondo hexadecimal (#rgb o #rrggbb):
     * oscuro sobre fondos claros y blanco sobre oscuros, según la luminancia
     * percibida (YIQ). Lo usa el avatar de las categorías, cuyo color elige el
     * usuario. Con un valor que no es un color, el color de texto del tema.
     */
    public function contrastText(?string $hex): string
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return 'var(--bs-body-color)';
        }

        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));

        return ($r * 299 + $g * 587 + $b * 114) / 1000 >= 128 ? '#212529' : '#fff';
    }
}
