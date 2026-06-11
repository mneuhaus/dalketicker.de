<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611222000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switch Weserticker to the Mühlenkreis green accent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET theme_color = '#527d08'
            WHERE region_key = 'minden-luebbecke'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET theme_color = '#2563eb'
            WHERE region_key = 'minden-luebbecke'
        SQL);
    }
}
