<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20211104191514 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE INDEX is_identifier_type_idx ON search (is_identifier, is_type)');
        $this->addSql('ALTER TABLE source ADD last_indexed DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX vendor_class_idx ON vendor (class)');
        $this->addSql('CREATE INDEX vendor_name_idx ON vendor (name)');
        $this->addSql('CREATE INDEX vendor_rank_idx ON vendor (rank)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX is_identifier_type_idx ON search');
        $this->addSql('CREATE INDEX is_identifier_idx ON search (is_identifier)');
        $this->addSql('ALTER TABLE source DROP last_indexed');
        $this->addSql('DROP INDEX vendor_class_idx ON vendor');
        $this->addSql('DROP INDEX vendor_name_idx ON vendor');
        $this->addSql('DROP INDEX vendor_rank_idx ON vendor');
    }
}
