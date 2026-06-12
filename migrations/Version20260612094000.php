<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612094000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove misspelled Weserticker public domain aliases.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["weserticker.de","www.weserticker.de","weserticker.traefik.me"]'::json
            WHERE region_key = 'minden-luebbecke'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["weserticker.de","www.weserticker.de","veserticker.de","www.veserticker.de","weserticker.traefik.me"]'::json
            WHERE region_key = 'minden-luebbecke'
        SQL);
    }
}
