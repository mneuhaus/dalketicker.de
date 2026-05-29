<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Source;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Source>
 */
class SourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Source::class);
    }

    public function findByKey(string $key): ?Source
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return Source[] */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['name' => 'ASC']);
    }
}
