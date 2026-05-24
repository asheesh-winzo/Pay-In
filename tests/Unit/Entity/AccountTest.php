<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Exception\InsufficientFundsException;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testGetBalanceDecimalFormatsCorrectly(): void
    {
        $account = new Account('owner-1', 10050, 'EUR');
        $this->assertSame('100.50', $account->getBalanceDecimal());
    }

    public function testGetBalanceDecimalWithWholeNumber(): void
    {
        $account = new Account('owner-1', 10000, 'EUR');
        $this->assertSame('100.00', $account->getBalanceDecimal());
    }

    public function testDebitReducesBalance(): void
    {
        $account = new Account('owner-1', 10000, 'EUR');
        $account->debit(2500);
        $this->assertSame(7500, $account->getBalanceMinorUnits());
    }

    public function testDebitExactBalanceReducesToZero(): void
    {
        $account = new Account('owner-1', 5000, 'EUR');
        $account->debit(5000);
        $this->assertSame(0, $account->getBalanceMinorUnits());
    }

    public function testDebitThrowsInsufficientFundsException(): void
    {
        $account = new Account('owner-1', 1000, 'EUR');

        $this->expectException(InsufficientFundsException::class);
        $account->debit(1001);
    }

    public function testDebitThrowsOnZeroAmount(): void
    {
        $account = new Account('owner-1', 1000, 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        $account->debit(0);
    }

    public function testDebitThrowsOnNegativeAmount(): void
    {
        $account = new Account('owner-1', 1000, 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        $account->debit(-100);
    }

    public function testCreditIncreasesBalance(): void
    {
        $account = new Account('owner-1', 5000, 'EUR');
        $account->credit(2000);
        $this->assertSame(7000, $account->getBalanceMinorUnits());
    }

    public function testCreditThrowsOnZeroAmount(): void
    {
        $account = new Account('owner-1', 5000, 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        $account->credit(0);
    }

    public function testCreditThrowsOnNegativeAmount(): void
    {
        $account = new Account('owner-1', 5000, 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        $account->credit(-50);
    }

    public function testCurrencyIsUppercased(): void
    {
        $account = new Account('owner-1', 0, 'eur');
        $this->assertSame('EUR', $account->getCurrency());
    }

    public function testIsActiveByDefault(): void
    {
        $account = new Account('owner-1', 0, 'EUR');
        $this->assertTrue($account->isActive());
    }

    public function testDeactivate(): void
    {
        $account = new Account('owner-1', 0, 'EUR');
        $account->deactivate();
        $this->assertFalse($account->isActive());
    }

    public function testIdIsUuid(): void
    {
        $account = new Account('owner-1', 0, 'EUR');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $account->getId()
        );
    }
}
