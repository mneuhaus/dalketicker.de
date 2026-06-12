<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prepare final public regional domains as host aliases.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["paderticker.de","www.paderticker.de","paderticker.traefik.me"]'::json
            WHERE region_key = 'paderborn'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["weserticker.de","www.weserticker.de","veserticker.de","www.veserticker.de","weserticker.traefik.me"]'::json
            WHERE region_key = 'minden-luebbecke'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["www.sparrenticker.de","sparrenticker.neuhaus.nrw","sparrenticker.traefik.me"]'::json
            WHERE region_key = 'bielefeld'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["paderticker.de","www.paderticker.de","paderticker.traefik.me"]'::json
            WHERE region_key = 'paderborn'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["weserticker.de","www.weserticker.de","weserticker.traefik.me"]'::json
            WHERE region_key = 'minden-luebbecke'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["www.sparrenticker.de","sparrenticker.neuhaus.nrw","sparrenticker.traefik.me"]'::json
            WHERE region_key = 'bielefeld'
        SQL);
    }
}
