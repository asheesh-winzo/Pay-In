<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class TransferRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'source_account_id is required.')]
        #[Assert\Uuid(message: 'source_account_id must be a valid UUID.')]
        public readonly string $sourceAccountId,

        #[Assert\NotBlank(message: 'destination_account_id is required.')]
        #[Assert\Uuid(message: 'destination_account_id must be a valid UUID.')]
        public readonly string $destinationAccountId,

        #[Assert\NotBlank(message: 'amount is required.')]
        #[Assert\Regex(
            pattern: '/^\d+(\.\d{1,2})?$/',
            message: 'amount must be a positive decimal with up to 2 decimal places.'
        )]
        public readonly string $amount,

        #[Assert\NotBlank(message: 'currency is required.')]
        #[Assert\Currency(message: 'currency must be a valid ISO 4217 code.')]
        public readonly string $currency,

        #[Assert\Length(max: 255, maxMessage: 'description must be 255 characters or fewer.')]
        public readonly ?string $description = null,
    ) {}

    public function getAmountMinorUnits(): int
    {
        [$whole, $fraction] = array_pad(explode('.', $this->amount, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return (int) ($whole . $fraction);
    }
}
