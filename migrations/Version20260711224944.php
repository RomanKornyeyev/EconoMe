<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711224944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina day_of_execution: el calendario de las recurrentes se deriva ahora de start_date (lógica bancaria)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recurring_transaction DROP day_of_execution');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recurring_transaction ADD day_of_execution INT NOT NULL');
    }
}
