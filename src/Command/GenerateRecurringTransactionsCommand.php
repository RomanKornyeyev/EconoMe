<?php

namespace App\Command;

use App\Entity\Transaction;
use App\Repository\RecurringTransactionRepository;
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recurrings = $this->recurringRepo->findActiveForGeneration();
        $generated = 0;

        foreach ($recurrings as $recurring) {
            if (!$recurring->shouldGenerateToday()) {
                continue;
            }

            // Evitar duplicados: si ya se generó hoy, saltar
            if ($recurring->getLastGeneratedAt() !== null
                && $recurring->getLastGeneratedAt()->format('Y-m-d') === (new \DateTime())->format('Y-m-d')
            ) {
                continue;
            }

            $transaction = new Transaction($recurring->getAccount(), $recurring->getCreatedBy());
            $transaction->setType($recurring->getType());
            $transaction->setAmount($recurring->getAmount());
            $transaction->setDate(new \DateTime());
            $transaction->setName($recurring->getName());
            $transaction->setDescription($recurring->getDescription());
            $transaction->setCategory($recurring->getCategory());
            $transaction->setRecurringSource($recurring);

            $recurring->setLastGeneratedAt(new \DateTimeImmutable());

            $this->em->persist($transaction);
            $generated++;
        }

        $this->em->flush();

        $io->success(sprintf('Se generaron %d transacciones.', $generated));

        return Command::SUCCESS;
    }
}
