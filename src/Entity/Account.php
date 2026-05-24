<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InsufficientFundsException;
use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\HasLifecycleCallbacks]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $ownerId;

    #[ORM\Column(type: 'bigint')]
    private int $balanceMinorUnits;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer')]
    private int $version = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $ownerId, int $balanceMinorUnits, string $currency = 'EUR')
    {
        $this->id = Uuid::uuid4()->toString();
        $this->ownerId = $ownerId;
        $this->balanceMinorUnits = $balanceMinorUnits;
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        ++$this->version;
    }

    public function getId(): string { return $this->id; }
    public function getOwnerId(): string { return $this->ownerId; }
    public function getBalanceMinorUnits(): int { return $this->balanceMinorUnits; }
    public function getCurrency(): string { return $this->currency; }
    public function isActive(): bool { return $this->isActive; }
    public function getVersion(): int { return $this->version; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getBalanceDecimal(): string
    {
        return number_format($this->balanceMinorUnits / 100, 2, '.', '');
    }

    public function debit(int $amountMinorUnits): void
    {
        if ($amountMinorUnits <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        if ($this->balanceMinorUnits < $amountMinorUnits) {
            throw new InsufficientFundsException($this->id);
        }

        $this->balanceMinorUnits -= $amountMinorUnits;
    }

    public function credit(int $amountMinorUnits): void
    {
        if ($amountMinorUnits <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        $this->balanceMinorUnits += $amountMinorUnits;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }
}
