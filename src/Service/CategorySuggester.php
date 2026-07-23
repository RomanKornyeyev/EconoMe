<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;

/**
 * Sugiere una categoría para un movimiento a partir del historial de la cuenta.
 *
 * Idea de diseño: el mejor predictor de la categoría de un movimiento es qué
 * categoría le puso el propio usuario a otros movimientos con el mismo nombre.
 * Esa señal es personal, gratis y mejora sola con el uso. No hay IA ni llamadas
 * externas: solo una consulta agrupada y un poco de política aquí.
 *
 * El servicio es de solo lectura y sin estado: no persiste, no sabe de HTTP ni
 * de formularios. Por eso es reutilizable (endpoint AJAX, importador CSV, un
 * comando de recategorización en lote, etc.).
 *
 * Niveles previstos, por precedencia (hoy solo el 2 está implementado):
 *   1. Reglas keyword→categoría explícitas del usuario  [futuro]
 *   2. Histórico por nombre exacto (este)               [ahora]
 *   3. Coincidencia difusa por nombre parecido          [futuro]
 */
final class CategorySuggester
{
    /**
     * Nº mínimo de caracteres del nombre para molestarse en sugerir.
     * Por debajo, la señal es ruido.
     */
    private const MIN_NAME_LENGTH = 2;

    public function __construct(
        private TransactionRepository $transactionRepo,
        private CategoryRepository $categoryRepo,
    ) {}

    /**
     * Mejor categoría para un movimiento de la cuenta con este nombre y tipo,
     * o null si no hay señal suficiente en el historial.
     */
    public function suggestFor(Account $account, string $name, string $type): ?CategorySuggestion
    {
        $name = $this->normalize($name);

        if (mb_strlen($name) < self::MIN_NAME_LENGTH) {
            return null;
        }
        if ($type !== Transaction::TYPE_EXPENSE && $type !== Transaction::TYPE_INCOME) {
            return null;
        }

        $rows = $this->transactionRepo->topCategoriesByName($account, $name, $type);
        if ($rows === []) {
            return null;
        }

        // La primera fila ya es la mejor candidata (más frecuente, desempate por
        // más reciente). La confianza es su peso sobre el total de coincidencias.
        $best  = $rows[0];
        $total = array_sum(array_map(fn (array $r) => $r['cnt'], $rows));

        $category = $this->categoryRepo->find($best['categoryId']);
        // La categoría pudo borrarse entre la consulta y ahora (carrera muy
        // improbable): sin entidad no hay nada que sugerir.
        if ($category === null) {
            return null;
        }

        return new CategorySuggestion(
            category: $category,
            confidence: $total > 0 ? $best['cnt'] / $total : 0.0,
            source: 'exact',
            matches: $best['cnt'],
        );
    }

    /**
     * Normaliza el nombre para comparar: recorta y colapsa espacios internos.
     *
     * No toca mayúsculas ni acentos a propósito: de eso ya se encarga la
     * collation de MySQL en la consulta ({@see TransactionRepository::topCategoriesByName}),
     * y así el nombre que se compara sigue siendo el que el usuario escribió.
     */
    private function normalize(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
