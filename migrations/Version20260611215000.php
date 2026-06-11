<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611215000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switch Sparrenticker to the official Bielefeld red accent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET theme_color = '#e30014'
            WHERE region_key = 'bielefeld'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET theme_color = '#b45309'
            WHERE region_key = 'bielefeld'
        SQL);
    }
}
