<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230411010956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE search ADD generic_cover TINYINT(1) NOT NULL AFTER is_type');
        $this->addSql('ALTER TABLE source ADD generic_cover TINYINT(1) NOT NULL AFTER match_type');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE search DROP generic_cover');
        $this->addSql('ALTER TABLE source DROP generic_cover');
    }
}
