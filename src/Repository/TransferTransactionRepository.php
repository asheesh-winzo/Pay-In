<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TransferTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransferTransaction>
 */
class TransferTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransferTransaction::class);
    }

    public function findByIdempotencyKey(string $key): ?TransferTransaction
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }

    public function findByAccountId(string $accountId, int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.sourceAccountId = :id OR t.destinationAccountId = :id')
            ->setParameter('id', $accountId)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
