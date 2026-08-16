<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Crea el movimiento en blanco que alimenta el formulario de alta.
 *
 * Existe por la "fecha pegajosa": al registrar movimientos atrasados (un mes de
 * extracto), el valor por defecto útil no es hoy, sino la última fecha con la
 * que ese usuario guardó algo en esa cuenta. Sin esto hay que corregir la fecha
 * en cada uno de los movimientos, que es el gesto más caro de todo el flujo.
 *
 * El borrador se construye en tres sitios (listado, dashboard y formulario de
 * página completa); la lógica vive aquí para no tenerla escrita tres veces.
 *
 * El alcance es la sesión a propósito: la fecha es un apaño para la tanda que
 * estás metiendo ahora, no una preferencia del usuario. Al volver mañana, lo
 * correcto vuelve a ser hoy.
 */
final class TransactionDraftFactory
{
    private const SESSION_PREFIX = 'tx.last_date.';

    public function __construct(
        private RequestStack $requestStack,
    ) {}

    /**
     * Movimiento nuevo para la cuenta, con la última fecha usada en ella si la
     * hay. El constructor de Transaction ya deja hoy como valor por defecto.
     */
    public function create(Account $account, User $user): Transaction
    {
        $transaction = new Transaction($account, $user);

        $last = $this->lastDateFor($account);
        if ($last !== null) {
            $transaction->setDate($last);
        }

        return $transaction;
    }

    /**
     * Recuerda la fecha de un movimiento recién guardado, para que el siguiente
     * alta en esa cuenta arranque donde lo dejó el usuario.
     */
    public function remember(Transaction $transaction): void
    {
        $date = $transaction->getDate();
        if ($date === null) {
            return;
        }

        $this->session()?->set(
            self::SESSION_PREFIX . $transaction->getAccount()->getId(),
            $date->format('Y-m-d')
        );
    }

    private function lastDateFor(Account $account): ?\DateTimeInterface
    {
        $raw = $this->session()?->get(self::SESSION_PREFIX . $account->getId());
        if (!is_string($raw)) {
            return null;
        }

        // Una sesión manipulada o arrastrada de otra versión no debe reventar
        // el formulario: sin fecha válida, se cae al valor por defecto.
        $date = \DateTime::createFromFormat('Y-m-d', $raw);

        return $date ? $date->setTime(0, 0, 0) : null;
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->hasSession() ? $request->getSession() : null;
    }
}
