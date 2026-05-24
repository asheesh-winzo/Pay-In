<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\AccountNotFoundException;
use App\Service\AccountService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/accounts', name: 'api_v1_accounts_')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'status'  => 'error',
                'message' => 'Request body must be valid JSON.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $ownerId  = (string) ($data['owner_id'] ?? '');
        $balance  = (string) ($data['initial_balance'] ?? '0.00');
        $currency = strtoupper((string) ($data['currency'] ?? 'EUR'));

        $violations = $this->validator->validate($ownerId, [
            new Assert\NotBlank(),
            new Assert\Length(max: 100),
        ]);

        if (count($violations) > 0) {
            return $this->json([
                'status'  => 'error',
                'message' => 'owner_id is required.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $account = $this->accountService->create($ownerId, $currency, $balance);
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'status'  => 'error',
                'message' => sprintf('Account with owner_id "%s" already exists.', $ownerId),
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error creating account', ['exception' => $e]);

            return $this->json([
                'status'  => 'error',
                'message' => 'An internal error occurred.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'status' => 'success',
            'data'   => $this->serializeAccount($account),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            $account = $this->accountService->getActiveById($id);
        } catch (AccountNotFoundException $e) {
            return $this->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'status' => 'success',
            'data'   => $this->serializeAccount($account),
        ]);
    }

    private function serializeAccount(\App\Entity\Account $account): array
    {
        return [
            'id'         => $account->getId(),
            'owner_id'   => $account->getOwnerId(),
            'balance'    => $account->getBalanceDecimal(),
            'currency'   => $account->getCurrency(),
            'is_active'  => $account->isActive(),
            'created_at' => $account->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
