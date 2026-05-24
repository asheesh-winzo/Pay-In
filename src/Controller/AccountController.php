<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\TransferTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

#[Route('/api/v1/accounts', name: 'api_v1_accounts_')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly TransferTransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Request body must be valid JSON.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $ownerId  = (string)($data['owner_id'] ?? '');
        $balance  = (string)($data['initial_balance'] ?? '0.00');
        $currency = strtoupper((string)($data['currency'] ?? 'EUR'));

        $violations = $this->validator->validate($ownerId, [
            new Assert\NotBlank(),
            new Assert\Length(max: 100),
        ]);

        if (count($violations) > 0) {
            return $this->json([
                'status' => 'error',
                'message' => 'owner_id is required.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        [$whole, $fraction] = array_pad(explode('.', $balance, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $balanceMinorUnits = (int)($whole . $fraction);

        $account = new Account($ownerId, $balanceMinorUnits, $currency);

        try {
            $this->entityManager->persist($account);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            return $this->json([
                'status' => 'error',
                'message' => sprintf('Account with owner_id "%s" already exists.', $ownerId),
            ], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'status' => 'success',
            'data' => [
                'id' => $account->getId(),
                'owner_id' => $account->getOwnerId(),
                'balance' => $account->getBalanceDecimal(),
                'currency' => $account->getCurrency(),
                'is_active' => $account->isActive(),
                'created_at' => $account->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $account = $this->accountRepository->findActiveById($id);

        if ($account === null) {
            return $this->json([
                'status' => 'error',
                'message' => 'Account not found.'
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'status' => 'success',
            'data' => [
                'id' => $account->getId(),
                'owner_id' => $account->getOwnerId(),
                'balance' => $account->getBalanceDecimal(),
                'currency' => $account->getCurrency(),
                'is_active' => $account->isActive(),
                'created_at' => $account->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ]
        ]);
    }
}
