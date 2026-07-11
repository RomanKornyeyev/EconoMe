<?php

namespace App\Command;

use App\Repository\RecurringTransactionRepository;
use App\Service\RecurringMaterializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-recurring-transactions',
    description: 'Genera transacciones a partir de recurrentes activas',
)]
class GenerateRecurringTransactionsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private RecurringTransactionRepository $recurringRepo,
        private RecurringMaterializer $materializer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $generated = 0;
        $errors = 0;

        foreach ($this->recurringRepo->findActiveForGeneration() as $recurring) {
            try {
                // Ventana desde el cursor (catch-up si el comando estuvo días sin
                // ejecutarse); la deduplicación por fecha hace la operación idempotente.
                $generated += $this->materializer->generateMissing(
                    $recurring,
                    $recurring->getLastGeneratedAt(),
                );
            } catch (\DomainException $e) {
                $errors++;
                $io->warning(sprintf('Recurrente #%d «%s»: %s', $recurring->getId(), $recurring->getName(), $e->getMessage()));
            }
        }

        $this->em->flush();

        if ($errors > 0) {
            $io->warning(sprintf('Se generaron %d transacciones (%d recurrentes con error).', $generated, $errors));

            return Command::FAILURE;
        }

        $io->success(sprintf('Se generaron %d transacciones.', $generated));

        return Command::SUCCESS;
    }
}
