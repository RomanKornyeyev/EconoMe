<?php

namespace App\Service;

use App\Entity\Category;

/**
 * Resultado de sugerir una categoría para un movimiento.
 *
 * Value object inmutable: lo produce {@see CategorySuggester} y lo consumen
 * el endpoint AJAX, un futuro importador, etc. No tiene lógica; solo transporta
 * la categoría propuesta y por qué.
 */
final readonly class CategorySuggestion
{
    /**
     * @param Category $category   Categoría propuesta.
     * @param float    $confidence 0..1 — proporción de movimientos con ese
     *                             nombre que usaron esta categoría.
     * @param string   $source     Nivel que la produjo: 'exact' (histórico por
     *                             nombre). Reservado para 'keyword' y 'fuzzy'.
     * @param int      $matches    Nº de movimientos que respaldan la sugerencia.
     */
    public function __construct(
        public Category $category,
        public float $confidence,
        public string $source,
        public int $matches,
    ) {}
}
