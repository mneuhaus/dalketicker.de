<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Service\CatalogSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Dev/test fixtures: delegate to {@see CatalogSeeder} so the seeding logic
 * lives in one place and is also reachable in prod via `dalketicker:seed`.
 */
final class AppFixtures extends Fixture
{
    public function __construct(private readonly CatalogSeeder $seeder)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->seeder->seedCatalog();
        $this->seeder->seedDemoEvents();
    }
}
