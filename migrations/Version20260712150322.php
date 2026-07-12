<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712150322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade completed_tours (tours de onboarding vistos) a user_settings';
    }

    public function up(Schema $schema): void
    {
        // JSON no admite DEFAULT literal en MySQL 8: se añade nullable,
        // se rellena y después se endurece a NOT NULL.
        $this->addSql('ALTER TABLE user_settings ADD completed_tours JSON DEFAULT NULL');
        $this->addSql("UPDATE user_settings SET completed_tours = '[]'");
        $this->addSql('ALTER TABLE user_settings MODIFY completed_tours JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_settings DROP completed_tours');
    }
}
