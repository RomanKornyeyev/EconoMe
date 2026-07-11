<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\RecurringTransaction;
use App\Form\RecurringTransactionType;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TransactionRepository;
use App\Service\AccountService;
use App\Service\RecurringMaterializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/recurring', name: 'recurring_')]
class RecurringTransactionController extends AbstractController
{
    public function __construct(
        private AccountService $accountService,
        private RecurringTransactionRepository $recurringRepo,
        private TransactionRepository $transactionRepo,
        private EntityManagerInterface $em,
        private RecurringMaterializer $materializer,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $accounts = $this->accountService->getActiveAccountsForUser($user);

        if (empty($accounts)) {
            return $this->redirectToRoute('account_create');
        }

        $accountId = $request->query->getInt('account', $accounts[0]->getId());
        $account = $this->em->getRepository(Account::class)->find($accountId);
        $this->denyAccessUnlessGranted('ACCOUNT_VIEW', $account);

        $recurrings = $this->recurringRepo->findByAccount($account);

        return $this->render('recurring/index.html.twig', [
            'accounts'        => $accounts,
            'currentAccount'  => $account,
            'recurrings'      => $recurrings,
            'generatedCounts' => $this->transactionRepo->countByRecurringSourceForAccount($account),
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request): Response
    {
        $accountId = $request->query->getInt('account');
        $account = $this->em->getRepository(Account::class)->find($accountId);

        if (!$account) {
            $this->addFlash('error', 'Cuenta no encontrada.');
            return $this->redirectToRoute('recurring_index');
        }

        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $account);

        $recurring = new RecurringTransaction($account, $this->getUser());
        $form = $this->createForm(RecurringTransactionType::class, $recurring, [
            'currency' => $account->getCurrency(),
            'account'  => $account,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Backfill: materializa las ocurrencias desde startDate hasta hoy.
                $created = $this->materializer->generateMissing($recurring);
                $this->em->persist($recurring);
                $this->em->flush();

                $this->addFlash('success', $created > 0
                    ? sprintf('Transacción recurrente creada. Se han generado %d movimientos.', $created)
                    : 'Transacción recurrente creada.');

                return $this->redirectToRoute('recurring_index', ['account' => $account->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('recurring/create.html.twig', [
            'form'    => $form,
            'account' => $account,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(RecurringTransaction $recurring, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $recurring->getAccount());

        // Snapshot del calendario antes del submit, para detectar cambios que
        // alteran QUÉ ocurrencias existen (no solo sus valores).
        $calendarBefore = $this->calendarSnapshot($recurring);

        $form = $this->createForm(RecurringTransactionType::class, $recurring, [
            'currency' => $recurring->getAccount()->getCurrency(),
            'account'  => $recurring->getAccount(),
            'is_edit'  => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $calendarChanged = $calendarBefore !== $this->calendarSnapshot($recurring);

            try {
                $synced = ['created' => 0, 'deleted' => 0];

                if ($calendarChanged) {
                    $plan = $this->materializer->computeCalendarSyncPlan($recurring);
                    $impact = \count($plan['create']) + \count($plan['delete']);

                    // Confirmación de impacto: si el re-sync toca movimientos y el
                    // usuario aún no ha confirmado, mostramos el resumen sin guardar
                    // nada (no hay flush, los cambios en memoria se descartan).
                    if ($impact > 0 && !$request->request->getBoolean('sync_confirmed')) {
                        return $this->render('recurring/confirm_sync.html.twig', [
                            'recurring'   => $recurring,
                            'createCount' => \count($plan['create']),
                            'deleteCount' => \count($plan['delete']),
                            'payload'     => $request->request->all(),
                        ]);
                    }

                    $synced = $this->materializer->applyCalendarSyncPlan($recurring, $plan);
                }

                $updated = 0;
                if ($form->get('applyToGenerated')->getData()) {
                    $updated = $this->materializer->applyValuesToGenerated($recurring);
                }

                $this->em->flush();

                $detail = [];
                if ($synced['created'] > 0) $detail[] = sprintf('%d movimientos creados', $synced['created']);
                if ($synced['deleted'] > 0) $detail[] = sprintf('%d eliminados', $synced['deleted']);
                if ($updated > 0)           $detail[] = sprintf('%d actualizados', $updated);

                $this->addFlash('success', 'Transacción recurrente actualizada.'
                    . ($detail ? ' (' . implode(', ', $detail) . ')' : ''));

                return $this->redirectToRoute('recurring_index', ['account' => $recurring->getAccount()->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('recurring/edit.html.twig', [
            'form'      => $form,
            'recurring' => $recurring,
        ]);
    }

    /** Campos que definen qué ocurrencias existen. */
    private function calendarSnapshot(RecurringTransaction $recurring): array
    {
        return [
            'frequency' => $recurring->getFrequency(),
            'day'       => $recurring->getDayOfExecution(),
            'start'     => $recurring->getStartDate()?->format('Y-m-d'),
            'end'       => $recurring->getEndDate()?->format('Y-m-d'),
        ];
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(RecurringTransaction $recurring, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $recurring->getAccount());

        if ($this->isCsrfTokenValid('toggle' . $recurring->getId(), $request->request->get('_token'))) {
            $recurring->setIsActive(!$recurring->isActive());

            if ($recurring->isActive()) {
                // Avanzar el cursor a hoy: el periodo en pausa no se rellena
                // retroactivamente al reactivar.
                $recurring->setLastGeneratedAt(new \DateTimeImmutable('today'));
            }

            $this->em->flush();

            $status = $recurring->isActive() ? 'activada' : 'desactivada';
            $this->addFlash('success', "Transacción recurrente $status.");
        }

        return $this->redirectToRoute('recurring_index', ['account' => $recurring->getAccount()->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(RecurringTransaction $recurring, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ACCOUNT_EDIT', $recurring->getAccount());
        $accountId = $recurring->getAccount()->getId();

        if ($this->isCsrfTokenValid('delete' . $recurring->getId(), $request->request->get('_token'))) {
            // Opt-in: por defecto se conserva el historial (la FK queda a NULL).
            $deletedGenerated = 0;
            if ($request->request->getBoolean('delete_generated')) {
                $deletedGenerated = $this->transactionRepo->deleteByRecurringSource($recurring);
            }

            $this->em->remove($recurring);
            $this->em->flush();

            $this->addFlash('success', $deletedGenerated > 0
                ? sprintf('Transacción recurrente eliminada junto con %d movimientos generados.', $deletedGenerated)
                : 'Transacción recurrente eliminada.');
        }

        return $this->redirectToRoute('recurring_index', ['account' => $accountId]);
    }
}
