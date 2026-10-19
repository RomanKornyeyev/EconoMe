<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\TransactionRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Cómo se usa cada categoría: cuántos movimientos tiene de cada tipo
 * (gasto/ingreso).
 *
 * Sustituye al antiguo tipo fijo de la categoría: ahora se deduce del historial
 * de movimientos. Lo consumen los desplegables de categoría (formularios y
 * filtros) para agrupar u ocultar opciones según el tipo elegido, y el listado
 * de categorías para pintar «Se usa en» y el nº de movimientos.
 *
 * Memoriza el resultado por cuenta durante la petición: en el listado de
 * movimientos lo piden a la vez los filtros y el formulario del alta rápida, y
 * así cuesta una sola consulta. Se vacía entre peticiones (ResetInterface).
 */
final class CategoryUsage implements ResetInterface
{
    /** @var array<int, array<int, array{expense: int, income: int}>> id de cuenta => resultado */
    private array $cache = [];

    public function __construct(
        private TransactionRepository $transactionRepo,
    ) {}

    /**
     * @return array<int, array{expense: int, income: int}> id de categoría => nº
     *         de movimientos por tipo (las categorías sin movimientos no aparecen)
     */
    public function countsByCategory(Account $account): array
    {
        return $this->cache[$account->getId()] ??= $this->transactionRepo->countByCategoryAndType($account);
    }

    /**
     * @return array<int, list<string>> id de categoría => tipos usados
     *         (las categorías sin movimientos no aparecen)
     */
    public function typesByCategory(Account $account): array
    {
        return array_map(
            fn (array $counts) => array_keys(array_filter($counts)),
            $this->countsByCategory($account),
        );
    }

    /**
     * Tipos usados por una categoría como cadena separada por espacios, el
     * formato del atributo data-used-as que leen los controladores Stimulus.
     */
    public function usedAs(Account $account, ?int $categoryId): string
    {
        return implode(' ', $this->typesByCategory($account)[$categoryId] ?? []);
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
