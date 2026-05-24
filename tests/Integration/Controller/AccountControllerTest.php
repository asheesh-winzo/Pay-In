<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for AccountController.
 *
 * Requires a running database (run inside Docker or with a local test DB).
 * Each test is wrapped in a transaction and rolled back automatically
 * by DAMA DoctrineTestBundle — so tests remain isolated.
 */
final class AccountControllerTest extends WebTestCase
{
    public function testCreateAccountReturns201(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'owner_id'        => 'user-integration-test-1',
            'initial_balance' => '100.00',
            'currency'        => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame('user-integration-test-1', $body['data']['owner_id']);
        $this->assertSame('100.00', $body['data']['balance']);
        $this->assertSame('EUR', $body['data']['currency']);
        $this->assertTrue($body['data']['is_active']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function testCreateAccountDefaultsToZeroBalance(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'owner_id' => 'user-integration-test-zero',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('0.00', $body['data']['balance']);
        $this->assertSame('EUR', $body['data']['currency']);
    }

    public function testCreateAccountReturnsBadRequestOnInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCreateAccountReturnsUnprocessableEntityWhenOwnerIdMissing(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'initial_balance' => '50.00',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCreateAccountReturnsConflictOnDuplicateOwnerId(): void
    {
        $client = static::createClient();
        $payload = json_encode(['owner_id' => 'user-duplicate-test']);

        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Second request with same owner_id
        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testShowAccountReturns200(): void
    {
        $client = static::createClient();

        // Create account first
        $client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'owner_id'        => 'user-show-test',
            'initial_balance' => '200.00',
            'currency'        => 'USD',
        ]));
        $created = json_decode($client->getResponse()->getContent(), true);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/accounts/' . $id);

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($id, $body['data']['id']);
        $this->assertSame('200.00', $body['data']['balance']);
    }

    public function testShowAccountReturns404ForUnknownId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/accounts/00000000-0000-4000-a000-000000000000');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
