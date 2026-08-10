<?php

namespace App\Pagination;

use Symfony\Component\HttpFoundation\Request;

/**
 * Registros por página que puede elegir el usuario en los listados.
 *
 * Las opciones viven aquí porque las consumen dos sitios con papeles distintos:
 * la plantilla las recorre para pintar el desplegable y el controlador valida
 * contra ellas. Si se separasen, el desplegable ofrecería un tamaño que el
 * backend descarta en silencio.
 *
 * El valor por defecto sí es cosa de cada listado: no pesa igual el listado
 * completo de movimientos que el del dashboard.
 */
final class PageSize
{
    public const OPTIONS = [10, 25, 50];

    /**
     * Descarta cualquier valor fuera de la lista —URL manipulada, o guardada
     * desde una versión con otras opciones— en favor del valor por defecto.
     */
    public static function fromRequest(Request $request, int $default): int
    {
        $perPage = $request->query->getInt('perPage', $default);

        return in_array($perPage, self::OPTIONS, true) ? $perPage : $default;
    }
}
