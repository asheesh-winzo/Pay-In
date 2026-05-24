<?php

declare(strict_types=1);

namespace App\Exception;

final class CurrencyMismatchException extends \DomainException
{
    public function __construct(string $expected, string $given)
    {
        parent::__construct(sprintf(
            'Currency mismatch: account currency is "%s", but transfer currency is "%s".',
            $expected,
            $given
        ));
    }
}
