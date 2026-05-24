<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Entity\TransferTransaction;
use App\Exception\AccountNotFoundException;
use App\Exception\CurrencyMismatchException;
use App\Exception\SameAccountTransferException;
use App\Repository\AccountRepository;
use App\Repository\TransferTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

final class TransferService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly TransferTransactionRepository $transactionRepository,
        private readonly RedisServiceInterface $redisService,
        private readonly LoggerInterface $logger,
    ) {}

    public function findById(string $id): ?TransferTransaction
    {
        return $this->transactionRepository->find($id);
    }

    public function transfer(TransferRequest $request, string $idempotencyKey): TransferTransaction
    {
        $cached = $this->getIdempotentResult($idempotencyKey);
        if ($cached !== null) {
            return $cached;
        }

        if ($request->sourceAccountId === $request->destinationAccountId) {
            throw new SameAccountTransferException();
        }

        $lockKeys = $this->buildLockKeys($request->sourceAccountId, $request->destinationAccountId);
        $lockFactory = new LockFactory(new RedisStore($this->redisService->getClient()));
        $locks = [];

        try {
            foreach ($lockKeys as $key) {
                $lock = $lockFactory->createLock($key, ttl: 10, autoRelease: true);

                if (!$lock->acquire(true)) {
                    throw new \RuntimeException('Could not acquire transfer lock.');
                }

                $locks[] = $lock;
            }

            return $this->executeTransfer($request, $idempotencyKey);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    private function executeTransfer(TransferRequest $request, string $idempotencyKey): TransferTransaction
    {
        $this->entityManager->beginTransaction();

        $transaction = new TransferTransaction(
            sourceAccountId: $request->sourceAccountId,
            destinationAccountId: $request->destinationAccountId,
            amountMinorUnits: $request->getAmountMinorUnits(),
            currency: strtoupper($request->currency),
            idempotencyKey: $idempotencyKey,
            description: $request->description,
        );

        try {
            $source = $this->accountRepository->findWithLock($request->sourceAccountId);
            if ($source === null || !$source->isActive()) {
                throw new AccountNotFoundException($request->sourceAccountId);
            }

            $destination = $this->accountRepository->findWithLock($request->destinationAccountId);
            if ($destination === null || !$destination->isActive()) {
                throw new AccountNotFoundException($request->destinationAccountId);
            }

            $this->validateCurrency($source, strtoupper($request->currency));
            $this->validateCurrency($destination, strtoupper($request->currency));

            $this->entityManager->persist($transaction);

            $source->debit($request->getAmountMinorUnits());
            $destination->credit($request->getAmountMinorUnits());

            $transaction->complete();

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->cacheIdempotentResult($idempotencyKey, $transaction->getId());

            return $transaction;
        } catch (\Throwable $e) {
            $transaction->fail($e->getMessage());
            $this->entityManager->rollback();
            $this->logger->error('Transfer failed', [
                'idempotency_key' => $idempotencyKey,
                'exception'       => $e,
            ]);
            throw $e;
        }
    }

    private function validateCurrency(Account $account, string $requestedCurrency): void
    {
        if (strtoupper($account->getCurrency()) !== $requestedCurrency) {
            throw new CurrencyMismatchException($account->getCurrency(), $requestedCurrency);
        }
    }

    private function buildLockKeys(string $sourceId, string $destinationId): array
    {
        $ids = [$sourceId, $destinationId];
        sort($ids);
        return array_map(fn(string $id) => 'transfer_lock:' . $id, $ids);
    }

    private function getIdempotentResult(string $key): ?TransferTransaction
    {
        $txId = $this->redisService->get('idempotency:' . $key);
        if ($txId !== null) {
            return $this->transactionRepository->find($txId);
        }

        return $this->transactionRepository->findByIdempotencyKey($key);
    }

    private function cacheIdempotentResult(string $key, string $transactionId): void
    {
        $this->redisService->set('idempotency:' . $key, $transactionId, 86400);
    }
}
