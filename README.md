# Pay-In — Fund Transfer API

A production-ready REST API for transferring funds between accounts, built with **PHP 8.3 + Symfony 7 + MySQL 8 + Redis 7**.

---

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [API Endpoints](#api-endpoints)
- [Quick Start (Docker)](#quick-start-docker)
- [Running Tests](#running-tests)
- [Design Decisions](#design-decisions)
- [Error Handling](#error-handling)
- [Scalability Notes & Future Improvements](#scalability-notes--future-improvements)
- [Making It Production Ready](#making-it-production-ready)
- [Common Docker Commands](#common-docker-commands)
- [Time Spent & AI Tools Used](#time-spent--ai-tools-used)

---

## Architecture Overview

```
POST /api/v1/transfers
        │
        ▼
TransferController          ← validates input (DTO + Symfony Validator)
        │                   ← checks X-Idempotency-Key header
        ▼
TransferService
  ├── Redis idempotency check   ← returns cached result if key already processed
  ├── Redis distributed lock    ← prevents race conditions (two concurrent transfers
  │                               on the same account)
  └── DB transaction
        ├── SELECT ... FOR UPDATE   ← pessimistic row-level lock on both accounts
        ├── debit source account
        ├── credit destination account
        └── persist TransferTransaction
```

**Money is stored as integer minor units (cents).** `€10.50` → `1050`. This eliminates all floating-point precision issues.

---

## API Endpoints

### Create Account

```
POST /api/v1/accounts
Content-Type: application/json

{
  "owner_id": "user-123",        // required, unique string (max 100 chars)
  "initial_balance": "100.00",   // optional, defaults to "0.00"
  "currency": "EUR"              // optional, defaults to "EUR", ISO 4217
}
```

**Responses:**
- `201 Created` — account created
- `400 Bad Request` — invalid JSON
- `409 Conflict` — owner_id already has an account
- `422 Unprocessable Entity` — validation failed

---

### Get Account

```
GET /api/v1/accounts/{id}
```

**Responses:**
- `200 OK` — account details
- `404 Not Found` — account not found or inactive

---

### Create Transfer

```
POST /api/v1/transfers
Content-Type: application/json
X-Idempotency-Key: <unique-client-generated-uuid>

{
  "source_account_id": "...",       // required, UUID
  "destination_account_id": "...", // required, UUID
  "amount": "50.00",               // required, positive decimal (max 2 decimal places)
  "currency": "EUR",               // required, ISO 4217
  "description": "Invoice #42"     // optional, max 255 chars
}
```

**Responses:**
- `201 Created` — transfer completed, returns transaction details
- `400 Bad Request` — missing idempotency key or invalid JSON
- `404 Not Found` — source or destination account not found
- `422 Unprocessable Entity` — validation error, same account, currency mismatch, insufficient funds
- `500 Internal Server Error` — unexpected failure (logged)

**Idempotency:** Sending the same `X-Idempotency-Key` twice will return the original transaction without executing the transfer again. The result is cached in Redis for 24 hours and falls back to a database lookup.

---

## Quick Start (Docker)

**Prerequisites:** Docker + Docker Compose

```bash
# 1. Clone the repo
git clone https://github.com/YOUR_USERNAME/pay-in.git
cd pay-in

# 2. Start all services (PHP-FPM, Nginx, MySQL, Redis)
docker compose up -d --build

# 3. Install PHP dependencies
docker compose exec app composer install

# 4. Run database migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 5. Verify the app is running
curl -X POST http://localhost:8080/api/v1/accounts \
  -H "Content-Type: application/json" \
  -d '{"owner_id": "alice", "initial_balance": "1000.00", "currency": "EUR"}'
```

App is live at **http://localhost:8080**

### Example: Full Transfer Flow

```bash
# Create source account
SOURCE=$(curl -s -X POST http://localhost:8080/api/v1/accounts \
  -H "Content-Type: application/json" \
  -d '{"owner_id":"alice","initial_balance":"500.00","currency":"EUR"}' \
  | jq -r '.data.id')

# Create destination account
DEST=$(curl -s -X POST http://localhost:8080/api/v1/accounts \
  -H "Content-Type: application/json" \
  -d '{"owner_id":"bob","initial_balance":"0.00","currency":"EUR"}' \
  | jq -r '.data.id')

# Transfer €75.00
curl -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -d "{
    \"source_account_id\": \"$SOURCE\",
    \"destination_account_id\": \"$DEST\",
    \"amount\": \"75.00\",
    \"currency\": \"EUR\",
    \"description\": \"Test payment\"
  }"
```

---

## Running Tests

Tests run inside Docker against the test database:

```bash
# Run all tests (unit + integration)
docker compose exec app php bin/phpunit

# Run only unit tests (no database/redis needed)
docker compose exec app php bin/phpunit tests/Unit

# Run only integration tests
docker compose exec app php bin/phpunit tests/Integration

# Run with verbose output
docker compose exec app php bin/phpunit --testdox
```

### Test Structure

```
tests/
├── Unit/
│   ├── Entity/AccountTest.php          — balance logic, debit/credit, domain rules
│   ├── DTO/TransferRequestTest.php     — amount minor-unit conversion
│   └── Service/TransferServiceTest.php — idempotency, same-account guard (mocked deps)
└── Integration/
    ├── Controller/AccountControllerTest.php   — full HTTP lifecycle for accounts
    └── Controller/TransferControllerTest.php  — full HTTP lifecycle for transfers
```

Integration tests use [`dama/doctrine-test-bundle`](https://github.com/dmaicher/doctrine-test-bundle) to wrap every test in a transaction that is rolled back after each test — leaving the database clean.

---

## Design Decisions

### Concurrency Safety (Two-Layer Locking)

The most critical requirement is that two concurrent transfers involving the same account must not produce incorrect balances.

**Layer 1 — Redis distributed lock (`symfony/lock` + `RedisStore`):**
- Acquired before entering the database transaction
- Lock keys are sorted by account UUID so two processes always acquire locks in the same order, preventing deadlocks
- TTL of 10 seconds prevents permanent lock if the process crashes

**Layer 2 — Pessimistic DB lock (`SELECT ... FOR UPDATE`):**
- Inside the transaction, both account rows are locked at the database level
- Provides correctness even if Redis is temporarily unavailable

### Idempotency

Every transfer requires a client-supplied `X-Idempotency-Key` header. The service:
1. Checks Redis cache for the key → returns cached transaction ID immediately if found
2. Falls back to a DB lookup (for cache misses after Redis restart)
3. After a successful transfer, caches the result in Redis with a 24-hour TTL

### Money as Minor Units (Cents)

All balances and amounts are stored as `BIGINT` in minor units (cents). `€10.50` is stored as `1050`. This avoids all floating-point arithmetic issues. The API accepts and returns decimal strings (`"10.50"`).

### Optimistic Version Field

The `Account` entity has a `version` column that increments on every update. This acts as a changelog / audit aid and lays the foundation for optimistic locking if the concurrency strategy ever shifts.

---

## Error Handling

All error responses follow a consistent shape:

```json
{
  "status": "error",
  "message": "Human-readable description"
}
```

Validation errors return an `errors` map:

```json
{
  "status": "error",
  "errors": {
    "amount": "amount must be a positive decimal with up to 2 decimal places.",
    "currency": "currency must be a valid ISO 4217 code."
  }
}
```

Unexpected errors are caught by the controller, logged via Monolog (structured JSON in production), and returned as a generic `500` — internal details are never leaked to the client.

---

## Scalability Notes & Future Improvements

The current implementation is solid for moderate load. Below are improvements that would be made for higher scale:

### Rate Limiting (not implemented — suggested for scale)

A per-client rate limiter (e.g., using Symfony's `symfony/rate-limiter` with the `RedisStore`) would protect the API from abuse. The Redis infrastructure is already in place. A sliding-window limiter keyed on IP or API key could be added as a Symfony event listener on `kernel.request` with minimal changes.

### Other Improvements

| Area | Current | Improvement |
|---|---|---|
| **Auth** | None | JWT / API-key middleware; scope transfers to authenticated user |
| **Pagination** | Repo method exists | Expose `GET /api/v1/accounts/{id}/transactions?page=1&limit=20` |
| **Async transfers** | Synchronous | For very high volume: push to a Symfony Messenger queue (RabbitMQ/SQS), process async, use webhooks to notify |
| **Observability** | Monolog logs | Add Prometheus metrics (transfer count, latency p99), structured JSON logs, tracing |
| **Soft delete** | `deactivate()` exists | Expose `DELETE /api/v1/accounts/{id}` endpoint |
| **DB read replicas** | Single DB | Route read-only queries to replica in `AccountRepository` |
| **Connection pooling** | php-fpm per-process | PgBouncer / ProxySQL for MySQL connection pooling at high concurrency |

---

## Making It Production Ready

This project is structured for a dev environment. Below is a checklist of what must be done before deploying to production.

### 1. Move credentials out of `docker-compose.yml`

Currently `docker-compose.yml` has hardcoded credentials:
```yaml
MYSQL_ROOT_PASSWORD: root_pass
MYSQL_PASSWORD: app_pass
```

In production, reference environment variables instead:
```yaml
# docker-compose.yml
environment:
  MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
  MYSQL_USER: ${MYSQL_USER}
  MYSQL_PASSWORD: ${MYSQL_PASSWORD}
```

Then create a `.env` file (gitignored) on the server with real values, or inject them via your CI/CD secrets manager (GitHub Actions secrets, AWS Secrets Manager, etc.).

---

### 2. Never commit `.env` — use `.env.example` as the template

`.env` is already gitignored in this repo. Anyone deploying should:
```bash
cp .env.example .env
# then fill in real values
```

---

### 3. Change `APP_SECRET` to a real random value

```bash
# Generate a strong secret
php -r "echo bin2hex(random_bytes(32));"
```

Set the output as `APP_SECRET` in your production `.env`. Never use the placeholder value.

---

### 4. Set `APP_ENV=prod`

```bash
APP_ENV=prod
```

This enables:
- Symfony's `fingers_crossed` log handler (only writes on error, prevents log flooding)
- Compiled DI container (faster boot)
- Disabled debug toolbar and profiler

Pre-warm the cache during deployment:
```bash
php bin/console cache:warmup --env=prod
```

---

### 5. Use strong, unique DB credentials

Replace `app_pass` / `root_pass` with strong generated passwords. The MySQL root password should never be the same as the application user password.

---

### 6. Do not expose MySQL and Redis ports publicly

In `docker-compose.yml`, the current config exposes:
```yaml
db:
  ports:
    - "3306:3306"   # ← remove in production
redis:
  ports:
    - "6379:6379"   # ← remove in production
```

Remove these `ports` entries in production — DB and Redis should only be reachable internally between containers on the Docker network, never from the public internet.

---

### 7. Purge leaked secrets from git history (if needed)

If `.env` was ever accidentally committed with real credentials:
```bash
# Requires git-filter-repo (brew install git-filter-repo)
git filter-repo --path .env --invert-paths
git push --force
```

Then rotate all leaked credentials immediately regardless.

---

### Summary Checklist

| # | Task | Status |
|---|---|---|
| 1 | Move `docker-compose.yml` credentials to env vars | ⬜ |
| 2 | Never commit `.env` — use `.env.example` | ✅ Already done |
| 3 | Set a real `APP_SECRET` | ⬜ |
| 4 | Set `APP_ENV=prod`, warmup cache | ⬜ |
| 5 | Use strong DB passwords | ⬜ |
| 6 | Remove DB/Redis port exposure | ⬜ |
| 7 | Add authentication (JWT / API key) | ⬜ |
| 8 | Set up HTTPS (SSL termination at Nginx or load balancer) | ⬜ |



Quick reference for the most frequent tasks when working with the Docker environment.

### After making code changes

Most PHP/config changes are picked up immediately (no restart needed). But for the cases below, you need to act:

```bash
# After changing any config YAML, services.yaml, .env, or adding new routes
docker compose exec app php bin/console cache:clear

# After changing src/ PHP files (normally auto-reloaded, but if something looks stale)
docker compose exec app php bin/console cache:warmup
```

---

### Nginx — reload or restart

```bash
# Reload config (zero downtime — use this after editing docker/nginx/default.conf)
docker compose exec nginx nginx -s reload

# Full restart of the Nginx container
docker compose restart nginx
```

---

### Redis — restart or flush

```bash
# Restart the Redis container (clears all in-memory data including locks + idempotency cache)
docker compose restart redis

# Flush all Redis data without restarting (useful during debugging)
docker compose exec redis redis-cli FLUSHALL

# Open Redis CLI interactively
docker compose exec redis redis-cli

# Inspect a specific key (e.g. idempotency cache)
docker compose exec redis redis-cli GET "idempotency:your-key-here"

# List all transfer lock keys
docker compose exec redis redis-cli KEYS "transfer_lock:*"
```

---

### MySQL — restart or connect

```bash
# Restart the DB container
docker compose restart db

# Open a MySQL shell
docker compose exec db mysql -u app_user -papp_pass pay_in

# Run a quick query from outside the shell
docker compose exec db mysql -u app_user -papp_pass pay_in -e "SELECT id, owner_id, balance_minor_units FROM accounts;"

# Re-run migrations (e.g. after pulling new migration files)
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

---

### Restart everything

```bash
# Stop and remove all containers (data volumes are preserved)
docker compose down

# Start everything again
docker compose up -d

# Full rebuild (after changing docker/php/Dockerfile or php.ini)
docker compose up -d --build
```

---

### View logs

```bash
# All containers live
docker compose logs -f

# Only app (PHP-FPM) logs
docker compose logs -f app

# Only Nginx access logs (JSON format — shows real URIs)
docker compose logs -f nginx

# Symfony application log file inside the container
docker compose exec app tail -f var/log/dev.log
```

---

## Time Spent & AI Tools Used

**Time spent:** ~3.5 hours

**AI tools used:** GitHub Copilot (code generation, test scaffolding, README drafting). All generated code was reviewed, understood, and adapted. Architecture decisions, locking strategy, and money-handling design are the author's own.

