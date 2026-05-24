<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransferTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: TransferTransactionRepository::class)]
#[ORM\Table(name: 'transfer_transactions')]
#[ORM\Index(columns: ['source_account_id'], name: 'idx_source_account')]
#[ORM\Index(columns: ['destination_account_id'], name: 'idx_destination_account')]
#[ORM\Index(columns: ['status'], name: 'idx_status')]
#[ORM\Index(columns: ['idempotency_key'], name: 'idx_idempotency_key')]
class TransferTransaction
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $sourceAccountId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $destinationAccountId;

    #[ORM\Column(type: 'bigint')]
    private int $amountMinorUnits;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        string $sourceAccountId,
        string $destinationAccountId,
        int $amountMinorUnits,
        string $currency,
        string $idempotencyKey,
        ?string $description = null,
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->sourceAccountId = $sourceAccountId;
        $this->destinationAccountId = $destinationAccountId;
        $this->amountMinorUnits = $amountMinorUnits;
        $this->currency = strtoupper($currency);
        $this->status = self::STATUS_PENDING;
        $this->idempotencyKey = $idempotencyKey;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function complete(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function fail(string $reason): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getSourceAccountId(): string { return $this->sourceAccountId; }
    public function getDestinationAccountId(): string { return $this->destinationAccountId; }
    public function getAmountMinorUnits(): int { return $this->amountMinorUnits; }
    public function getAmountDecimal(): string { return number_format($this->amountMinorUnits / 100, 2, '.', ''); }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getDescription(): ?string { return $this->description; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getFailureReason(): ?string { return $this->failureReason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
}
