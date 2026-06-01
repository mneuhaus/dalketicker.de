<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactMessage>
 */
class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    /**
     * Messages filtered by spam flag, newest first.
     *
     * @return ContactMessage[]
     */
    public function findBySpam(bool $spam, int $limit = 200): array
    {
        return $this->findBy(['spam' => $spam], ['createdAt' => 'DESC'], $limit);
    }

    /** @return array{real: int, spam: int, unseen: int} */
    public function counts(): array
    {
        return [
            'real' => (int) $this->count(['spam' => false]),
            'spam' => (int) $this->count(['spam' => true]),
            'unseen' => (int) $this->count(['spam' => false, 'seen' => false]),
        ];
    }
}
