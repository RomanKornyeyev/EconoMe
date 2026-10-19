<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Repository\TransactionRepository;
use App\Service\CategoryUsage;
use PHPUnit\Framework\TestCase;

/**
 * Las categorías no tienen tipo propio: con qué tipos se usa cada una se deduce
 * de los movimientos (TransactionRepository::countByCategoryAndType).
 */
class CategoryUsageTest extends TestCase
{
    /** Conteos por categoría como los devuelve el repositorio */
    private const COUNTS = [
        1 => ['expense' => 112, 'income' => 0],  // solo gastos
        2 => ['expense' => 0, 'income' => 18],   // solo ingresos
        3 => ['expense' => 40, 'income' => 6],   // mixta
    ];

    private function account(int $id): Account
    {
        $account = $this->createStub(Account::class);
        $account->method('getId')->willReturn($id);

        return $account;
    }

    public function testTypesByCategorySeDeducenDeLosConteos(): void
    {
        $repo = $this->createStub(TransactionRepository::class);
        $repo->method('countByCategoryAndType')->willReturn(self::COUNTS);
        $usage = new CategoryUsage($repo);

        $this->assertSame([
            1 => ['expense'],
            2 => ['income'],
            3 => ['expense', 'income'],
        ], $usage->typesByCategory($this->account(1)));
    }

    public function testUsedAsEsElFormatoDelAtributoDataUsedAs(): void
    {
        $repo = $this->createStub(TransactionRepository::class);
        $repo->method('countByCategoryAndType')->willReturn(self::COUNTS);
        $usage = new CategoryUsage($repo);
        $account = $this->account(1);

        $this->assertSame('expense', $usage->usedAs($account, 1));
        $this->assertSame('income', $usage->usedAs($account, 2));
        $this->assertSame('expense income', $usage->usedAs($account, 3));
        // Sin movimientos (o sin categoría): cadena vacía
        $this->assertSame('', $usage->usedAs($account, 99));
        $this->assertSame('', $usage->usedAs($account, null));
    }

    /** Filtros y formulario lo piden en la misma petición: una sola consulta por cuenta. */
    public function testMemorizaPorCuentaHastaElReset(): void
    {
        $repo = $this->createMock(TransactionRepository::class);
        $repo->expects($this->exactly(3))
            ->method('countByCategoryAndType')
            ->willReturn(self::COUNTS);
        $usage = new CategoryUsage($repo);
        $account = $this->account(1);

        // Misma cuenta: 1 consulta, aunque se pida por tres vías
        $usage->countsByCategory($account);
        $usage->typesByCategory($account);
        $usage->usedAs($account, 1);

        // Otra cuenta: su propia consulta
        $usage->countsByCategory($this->account(2));

        // Entre peticiones se vacía: vuelve a consultar
        $usage->reset();
        $usage->countsByCategory($account);
    }
}
