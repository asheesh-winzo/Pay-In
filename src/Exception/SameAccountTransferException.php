<?php

declare(strict_types=1);

namespace App\Exception;

final class SameAccountTransferException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Source and destination accounts cannot be the same.');
    }
}
