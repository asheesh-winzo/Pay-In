<?php

declare(strict_types=1);

namespace App\Exception;

final class InsufficientFundsException extends \DomainException
{
    public function __construct(string $accountId)
    {
        parent::__construct(sprintf('Account "%s" has insufficient funds for this transfer.', $accountId));
    }
}
