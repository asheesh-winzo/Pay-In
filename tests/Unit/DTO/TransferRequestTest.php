<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\TransferRequest;
use PHPUnit\Framework\TestCase;

final class TransferRequestTest extends TestCase
{
    public function testGetAmountMinorUnitsWithDecimal(): void
    {
        $dto = new TransferRequest('src-id', 'dst-id', '10.50', 'EUR');
        $this->assertSame(1050, $dto->getAmountMinorUnits());
    }

    public function testGetAmountMinorUnitsWithWholeNumber(): void
    {
        $dto = new TransferRequest('src-id', 'dst-id', '100', 'EUR');
        $this->assertSame(10000, $dto->getAmountMinorUnits());
    }

    public function testGetAmountMinorUnitsWithOneDecimalPlace(): void
    {
        $dto = new TransferRequest('src-id', 'dst-id', '5.5', 'EUR');
        $this->assertSame(550, $dto->getAmountMinorUnits());
    }

    public function testGetAmountMinorUnitsWithZeroCents(): void
    {
        $dto = new TransferRequest('src-id', 'dst-id', '25.00', 'EUR');
        $this->assertSame(2500, $dto->getAmountMinorUnits());
    }

    public function testGetAmountMinorUnitsLargeAmount(): void
    {
        $dto = new TransferRequest('src-id', 'dst-id', '9999.99', 'EUR');
        $this->assertSame(999999, $dto->getAmountMinorUnits());
    }
}
