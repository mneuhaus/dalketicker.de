<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611205500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the Kloster Dalheim Paderticker source.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'kloster_dalheim',
                enabled = TRUE,
                facts_only = FALSE,
                config = (COALESCE(config, '{}'::json)::jsonb || '{"city":"Lichtenau","fetchDetails":true}'::jsonb)::json
            WHERE source_key = 'kloster_dalheim_veranstaltungen'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'manual',
                enabled = FALSE
            WHERE source_key = 'kloster_dalheim_veranstaltungen'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
    }
}
