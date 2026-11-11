<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Presupuestos (v1.6.0): budget y sus tramos de importe, budget_amount.
 *
 * La categoría va con ON DELETE CASCADE, no SET NULL como en transaction: con
 * SET NULL, borrar la categoría convertiría su presupuesto en uno de toda la
 * cuenta sin avisar. Diseño en claude/presupuestos.md.
 */
final class Version20261111151255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea budget y budget_amount (presupuestos y sus tramos de importe)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE budget (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, frequency VARCHAR(10) NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, account_id INT NOT NULL, category_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_73F2F77B9B6B5FBA (account_id), INDEX IDX_73F2F77B12469DE2 (category_id), INDEX IDX_73F2F77BB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE budget_amount (id INT AUTO_INCREMENT NOT NULL, effective_from DATE NOT NULL, amount NUMERIC(10, 2) NOT NULL, budget_id INT NOT NULL, INDEX IDX_DA1436FE36ABA6B8 (budget_id), UNIQUE INDEX uniq_budget_amount_from (budget_id, effective_from), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE budget ADD CONSTRAINT FK_73F2F77B9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE budget ADD CONSTRAINT FK_73F2F77B12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE budget ADD CONSTRAINT FK_73F2F77BB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE budget_amount ADD CONSTRAINT FK_DA1436FE36ABA6B8 FOREIGN KEY (budget_id) REFERENCES budget (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget DROP FOREIGN KEY FK_73F2F77B9B6B5FBA');
        $this->addSql('ALTER TABLE budget DROP FOREIGN KEY FK_73F2F77B12469DE2');
        $this->addSql('ALTER TABLE budget DROP FOREIGN KEY FK_73F2F77BB03A8386');
        $this->addSql('ALTER TABLE budget_amount DROP FOREIGN KEY FK_DA1436FE36ABA6B8');
        $this->addSql('DROP TABLE budget');
        $this->addSql('DROP TABLE budget_amount');
    }
}
