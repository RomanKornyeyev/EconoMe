<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709185233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea account_invitation: invitaciones a cuentas pendientes de aceptar por el invitado.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account_invitation (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, responded_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, account_id INT NOT NULL, inviter_id INT NOT NULL, invitee_id INT NOT NULL, INDEX IDX_D8693069B6B5FBA (account_id), INDEX IDX_D869306B79F4F04 (inviter_id), INDEX IDX_D8693067A512022 (invitee_id), UNIQUE INDEX unique_invitation (account_id, invitee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_invitation ADD CONSTRAINT FK_D8693069B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE account_invitation ADD CONSTRAINT FK_D869306B79F4F04 FOREIGN KEY (inviter_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE account_invitation ADD CONSTRAINT FK_D8693067A512022 FOREIGN KEY (invitee_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_invitation DROP FOREIGN KEY FK_D8693069B6B5FBA');
        $this->addSql('ALTER TABLE account_invitation DROP FOREIGN KEY FK_D869306B79F4F04');
        $this->addSql('ALTER TABLE account_invitation DROP FOREIGN KEY FK_D8693067A512022');
        $this->addSql('DROP TABLE account_invitation');
    }
}
