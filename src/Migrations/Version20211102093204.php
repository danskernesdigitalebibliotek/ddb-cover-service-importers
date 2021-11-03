<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20211102093204 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE INDEX is_identifier_type_idx ON search (is_identifier, is_type)');
        $this->addSql('CREATE INDEX vendor_class_idx ON vendor (class)');
        $this->addSql('CREATE INDEX vendor_name_idx ON vendor (name)');
        $this->addSql('CREATE INDEX vendor_rank_idx ON vendor (rank)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX is_identifier_type_idx ON search');
        $this->addSql('DROP INDEX vendor_class_idx ON vendor');
        $this->addSql('DROP INDEX vendor_name_idx ON vendor');
        $this->addSql('DROP INDEX vendor_rank_idx ON vendor');
    }
}
