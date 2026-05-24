<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\TransferRequest;
use App\Entity\TransferTransaction;
use App\Exception\SameAccountTransferException;
use App\Repository\AccountRepository;
use App\Repository\TransferTransactionRepository;
use App\Service\RedisServiceInterface;
use App\Service\TransferService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TransferServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AccountRepository&MockObject $accountRepo;
    private TransferTransactionRepository&MockObject $txRepo;
    private RedisServiceInterface&MockObject $redisService;
    private TransferService $service;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->accountRepo  = $this->createMock(AccountRepository::class);
        $this->txRepo       = $this->createMock(TransferTransactionRepository::class);
        $this->redisService = $this->createMock(RedisServiceInterface::class);

        $this->service = new TransferService(
            $this->em,
            $this->accountRepo,
            $this->txRepo,
            $this->redisService,
            new NullLogger(),
        );
    }

    /**
     * Same-account guard fires before Redis locking, so no Redis interaction needed.
     */
    public function testThrowsSameAccountTransferException(): void
    {
        $this->redisService->method('get')->willReturn(null);
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);

        $dto = new TransferRequest('same-id', 'same-id', '10.00', 'EUR');

        $this->expectException(SameAccountTransferException::class);
        $this->service->transfer($dto, 'idempotency-key-1');
    }

    /**
     * Idempotency cache hit returns early — no locking, no DB write.
     */
    public function testReturnsCachedTransactionOnIdempotentRequest(): void
    {
        $existingTx = $this->createMock(TransferTransaction::class);

        $this->redisService->method('get')->willReturn('cached-tx-id');
        $this->txRepo->method('find')->willReturn($existingTx);
        // EntityManager must never be touched
        $this->em->expects($this->never())->method('beginTransaction');

        $dto = new TransferRequest(
            '00000000-0000-4000-a000-000000000001',
            '00000000-0000-4000-a000-000000000002',
            '10.00',
            'EUR',
        );

        $result = $this->service->transfer($dto, 'existing-key');

        $this->assertSame($existingTx, $result);
    }

    /**
     * DB fallback: Redis cache miss, but key exists in DB — still returns early.
     */
    public function testReturnsCachedTransactionFromDbFallback(): void
    {
        $existingTx = $this->createMock(TransferTransaction::class);

        $this->redisService->method('get')->willReturn(null);
        $this->txRepo->method('findByIdempotencyKey')->willReturn($existingTx);

        $dto = new TransferRequest(
            '00000000-0000-4000-a000-000000000001',
            '00000000-0000-4000-a000-000000000002',
            '10.00',
            'EUR',
        );

        $result = $this->service->transfer($dto, 'db-fallback-key');

        $this->assertSame($existingTx, $result);
        $this->em->expects($this->never())->method('beginTransaction');
    }
}
