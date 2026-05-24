<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\TransferRequest;
use App\Entity\TransferTransaction;
use App\Exception\AccountNotFoundException;
use App\Exception\CurrencyMismatchException;
use App\Exception\InsufficientFundsException;
use App\Exception\SameAccountTransferException;
use App\Service\TransferService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1', name: 'api_v1_')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly TransferService $transferService,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/transfers', name: 'transfer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $idempotencyKey = $request->headers->get('X-Idempotency-Key');
        if (empty($idempotencyKey)) {
            return $this->json([
                'status' => 'error',
                'message' => 'X-Idempotency-Key header is required.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Request body must be valid JSON.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $dto = new TransferRequest(
            sourceAccountId: (string) ($data['source_account_id'] ?? ''),
            destinationAccountId: (string) ($data['destination_account_id'] ?? ''),
            amount: (string) ($data['amount'] ?? ''),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            description: isset($data['description']) ? (string) $data['description'] : null,
        );

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return $this->json([
                'status' => 'error',
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $transaction = $this->transferService->transfer($dto, $idempotencyKey);

            return $this->json([
                'status' => 'success',
                'data' => $this->serializeTransaction($transaction),
            ], Response::HTTP_CREATED);
        } catch (SameAccountTransferException|CurrencyMismatchException|InsufficientFundsException $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccountNotFoundException $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected transfer error', ['exception' => $e]);

            return $this->json([
                'status' => 'error',
                'message' => 'An internal error occurred.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function serializeTransaction(TransferTransaction $tx): array
    {
        return [
            'id' => $tx->getId(),
            'source_account_id' => $tx->getSourceAccountId(),
            'destination_account_id' => $tx->getDestinationAccountId(),
            'amount' => $tx->getAmountDecimal(),
            'currency' => $tx->getCurrency(),
            'status' => $tx->getStatus(),
            'description' => $tx->getDescription(),
            'failure_reason' => $tx->getFailureReason(),
            'created_at' => $tx->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completed_at' => $tx->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
