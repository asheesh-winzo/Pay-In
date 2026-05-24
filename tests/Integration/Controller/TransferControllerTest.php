<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for TransferController.
 *
 * Each test is wrapped in a transaction rolled back by DAMA DoctrineTestBundle.
 * Redis is used for distributed locking and idempotency — requires Redis to be running.
 */
final class TransferControllerTest extends WebTestCase
{
    private function createAccount(object $client, string $ownerId, string $balance = '500.00', string $currency = 'EUR'): string
    {
        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'owner_id'        => $ownerId,
            'initial_balance' => $balance,
            'currency'        => $currency,
        ]));

        $body = json_decode($client->getResponse()->getContent(), true);
        return $body['data']['id'];
    }

    public function testTransferRequiresIdempotencyKeyHeader(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/transfers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'source_account_id'      => '00000000-0000-4000-a000-000000000001',
            'destination_account_id' => '00000000-0000-4000-a000-000000000002',
            'amount'                 => '10.00',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('error', $body['status']);
    }

    public function testTransferReturnsBadRequestOnInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-invalid-json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTransferReturnsUnprocessableEntityOnValidationErrors(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-validation',
        ], json_encode([
            'source_account_id'      => 'not-a-uuid',
            'destination_account_id' => '00000000-0000-4000-a000-000000000002',
            'amount'                 => 'abc',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $body);
    }

    public function testTransferReturnsSameAccountError(): void
    {
        $client = static::createClient();
        $accountId = $this->createAccount($client, 'same-account-owner');

        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-same-account',
        ], json_encode([
            'source_account_id'      => $accountId,
            'destination_account_id' => $accountId,
            'amount'                 => '10.00',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('same', strtolower($body['message']));
    }

    public function testSuccessfulTransfer(): void
    {
        $client    = static::createClient();
        $sourceId  = $this->createAccount($client, 'transfer-source', '200.00', 'EUR');
        $destId    = $this->createAccount($client, 'transfer-dest', '50.00', 'EUR');

        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-success-transfer',
        ], json_encode([
            'source_account_id'      => $sourceId,
            'destination_account_id' => $destId,
            'amount'                 => '75.00',
            'currency'               => 'EUR',
            'description'            => 'Test payment',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame('completed', $body['data']['status']);
        $this->assertSame('75.00', $body['data']['amount']);
        $this->assertSame('EUR', $body['data']['currency']);
        $this->assertSame('Test payment', $body['data']['description']);
        $this->assertNotNull($body['data']['completed_at']);
    }

    public function testTransferDeductsAndCreditsCorrectly(): void
    {
        $client   = static::createClient();
        $sourceId = $this->createAccount($client, 'balance-source-owner', '300.00', 'EUR');
        $destId   = $this->createAccount($client, 'balance-dest-owner', '100.00', 'EUR');

        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-balance-check',
        ], json_encode([
            'source_account_id'      => $sourceId,
            'destination_account_id' => $destId,
            'amount'                 => '120.00',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Verify source balance
        $client->request('GET', '/api/v1/accounts/' . $sourceId);
        $source = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('180.00', $source['data']['balance']);

        // Verify dest balance
        $client->request('GET', '/api/v1/accounts/' . $destId);
        $dest = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('220.00', $dest['data']['balance']);
    }

    public function testTransferReturnsUnprocessableEntityOnInsufficientFunds(): void
    {
        $client   = static::createClient();
        $sourceId = $this->createAccount($client, 'broke-source-owner', '10.00', 'EUR');
        $destId   = $this->createAccount($client, 'rich-dest-owner', '0.00', 'EUR');

        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-insufficient-funds',
        ], json_encode([
            'source_account_id'      => $sourceId,
            'destination_account_id' => $destId,
            'amount'                 => '50.00',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('error', $body['status']);
    }

    public function testTransferReturnsUnprocessableEntityOnCurrencyMismatch(): void
    {
        $client   = static::createClient();
        $sourceId = $this->createAccount($client, 'eur-source-owner', '100.00', 'EUR');
        $destId   = $this->createAccount($client, 'usd-dest-owner', '0.00', 'USD');

        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-currency-mismatch',
        ], json_encode([
            'source_account_id'      => $sourceId,
            'destination_account_id' => $destId,
            'amount'                 => '50.00',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTransferReturns404WhenAccountNotFound(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-not-found',
        ], json_encode([
            'source_account_id'      => '00000000-0000-4000-a000-000000000001',
            'destination_account_id' => '00000000-0000-4000-a000-000000000002',
            'amount'                 => '10.00',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testIdempotentTransferReturnsSameResult(): void
    {
        $client   = static::createClient();
        $sourceId = $this->createAccount($client, 'idempotent-source-owner', '500.00', 'EUR');
        $destId   = $this->createAccount($client, 'idempotent-dest-owner', '0.00', 'EUR');

        $payload = json_encode([
            'source_account_id'      => $sourceId,
            'destination_account_id' => $destId,
            'amount'                 => '100.00',
            'currency'               => 'EUR',
        ]);

        $headers = [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-idempotent-unique',
        ];

        $client->request('POST', '/api/v1/transfers', [], [], $headers, $payload);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = json_decode($client->getResponse()->getContent(), true);

        $client->request('POST', '/api/v1/transfers', [], [], $headers, $payload);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $second = json_decode($client->getResponse()->getContent(), true);

        // Same transaction ID returned both times
        $this->assertSame($first['data']['id'], $second['data']['id']);
    }

    public function testShowTransferReturnsTransaction(): void
    {
        $client   = static::createClient();
        $sourceId = $this->createAccount($client, 'show-source-owner', '200.00', 'EUR');
        $destId   = $this->createAccount($client, 'show-dest-owner', '0.00', 'EUR');

        $client->request('POST', '/api/v1/transfers', [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X-Idempotency-Key' => 'test-key-show-transfer',
        ], json_encode([
            'source_account_id'      => $sourceId,
            'destination_account_id' => $destId,
            'amount'                 => '50.00',
            'currency'               => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $txId = json_decode($client->getResponse()->getContent(), true)['data']['id'];

        $client->request('GET', '/api/v1/transfers/' . $txId);

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame($txId, $body['data']['id']);
        $this->assertSame('completed', $body['data']['status']);
        $this->assertSame('50.00', $body['data']['amount']);
        $this->assertSame('EUR', $body['data']['currency']);
    }

    public function testShowTransferReturns404ForUnknownId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/transfers/00000000-0000-4000-a000-000000000099');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('error', $body['status']);
    }
}
