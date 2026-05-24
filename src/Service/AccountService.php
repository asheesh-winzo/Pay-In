<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Exception\AccountNotFoundException;
use App\Repository\AccountRepository;
use App\Util\Money;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class AccountService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create a new account.
     *
     * @throws UniqueConstraintViolationException when owner_id already has an account
     */
    public function create(string $ownerId, string $currency, string $initialBalance): Account
    {
        $balanceMinorUnits = Money::toMinorUnits($initialBalance);

        $account = new Account($ownerId, $balanceMinorUnits, strtoupper($currency));

        try {
            $this->entityManager->persist($account);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw $e; // controller maps this to HTTP 409
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create account', [
                'owner_id'  => $ownerId,
                'exception' => $e,
            ]);
            throw $e;
        }

        $this->logger->info('Account created', [
            'account_id' => $account->getId(),
            'owner_id'   => $ownerId,
            'currency'   => $account->getCurrency(),
        ]);

        return $account;
    }

    /**
     * @throws AccountNotFoundException when account does not exist or is inactive
     */
    public function getActiveById(string $id): Account
    {
        $account = $this->accountRepository->findActiveById($id);

        if ($account === null) {
            throw new AccountNotFoundException($id);
        }

        return $account;
    }
}
