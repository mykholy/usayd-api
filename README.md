# Usayd Academy — Backend API

> **Repository:** `usayd-api`  
> **Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8.4 LTS · Redis 7.4  
> **Architecture:** Modular Monolith (ADR 001)  

---

## 1. Overview
The `usayd-api` repository houses the independent backend API server for Usayd Academy. It provides core identity, scheduling, booking, learning maps, supervisor operations, and payment management via strict, versioned REST endpoints (`/api/v1`) documented in `openapi.yaml`.

---

## 2. Setup & Local Development

### Prerequisites
- PHP `>= 8.3` (PHP `8.4` recommended)
- Composer `2.x`
- MySQL `8.4 LTS` (InnoDB strict SQL mode)
- Redis `7.4`

### Installation
```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
php artisan key:generate

# 3. Run database migrations on MySQL 8.4
php artisan migrate:fresh
```

---

## 3. Health & Readiness Verification

- **Production Health Probe:** `GET /api/v1/health`
- **Root Health Alias:** `GET /api/health`
- **Application Up Probe:** `GET /up`

Returns HTTP 200 with JSON subsystem connectivity (database, redis, runtime latency). Returns HTTP 503 with RFC 7807 problem details if database connection fails.

---

## 4. Quality & Test Execution

```bash
# Validate composer configuration
composer validate --strict

# Run automated test battery against MySQL 8.4 service database
php artisan test

# Verify code styling (Laravel Pint)
./vendor/bin/pint --test

# Verify OpenAPI contract alignment
bash scripts/check-openapi.sh

# Verify architecture domain boundaries
python scripts/verify_architecture_boundaries.py
```
