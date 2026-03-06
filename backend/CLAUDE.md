# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Backend (dentro de backend/)
composer setup      # install deps, gera .env, roda migrations, builda assets
composer dev        # PHP + queue + logs (concorrentemente)
composer test       # Pest

# Run a single test file
./vendor/bin/pest tests/Feature/TenantScopeTest.php

# Run tests matching a description
./vendor/bin/pest --filter "filtra registros pelo tenant"

# Code formatting
./vendor/bin/pint

# Database migrations
php artisan migrate
php artisan migrate:fresh --seed

# Frontend (dentro de frontend/)
npm install
npm run dev         # Vite dev server
npm run build       # build produção
```

## Architecture

Este é um **SaaS multi-tenant** (Laravel 12) com **identificação por subdomínio**, banco compartilhado e query scoping automático. O frontend é uma **Vue 3 SPA** desacoplada que consome a API Laravel via Axios + Sanctum.

### Estrutura do Monorepo

- `backend/` — Laravel (API pura, PHP-FPM)
- `frontend/` — Vue 3 SPA (Vite, Composition API)
- `docker-compose.yml` — orquestra todos os serviços na raiz

### Comunicação Frontend ↔ Backend

- **Axios** com header `Authorization: Bearer {token}` em todas as requisições
- **Token Sanctum** obtido via `POST /api/auth/login`, armazenado em `localStorage`
- **CORS** configurado em `config/cors.php` para aceitar o domínio do frontend

### Multi-Tenancy Flow

1. **`IdentifyTenant` middleware** (`app/Http/Middleware/IdentifyTenant.php`) runs on every request, extracts the subdomain, queries the `Tenant` model, and binds the result to the container: `app()->instance('tenant', $tenant)`.
2. **`AppServiceProvider`** binds `'tenant'` to `null` by default so `app('tenant')` always resolves.
3. **`BelongsToTenant` trait** (`app/BelongsToTenant.php`) is applied to models that belong to a tenant. It registers `TenantScope` as a global query scope and auto-sets `tenant_id` on model creation from `app('tenant')->id`.
4. **`TenantScope`** (`app/Models/Scopes/TenantScope.php`) is a global scope that transparently filters all queries by `tenant_id`. It's a no-op when `app('tenant')` is null.

To add a new tenant-scoped model: add `use BelongsToTenant;` and include `tenant_id` in `$fillable`.

### Key Domain Models

- **`Tenant`** — has `nome`, `subdominio`, `plano` fields
- **`User`** — belongs to a tenant, has a `role` field

### Testing

Tests use **Pest PHP** with an in-memory SQLite database (configured in `phpunit.xml`). All feature tests extend `Tests\TestCase` via Pest's `uses()` in `tests/Pest.php`.

When testing tenant-scoped behavior, bind a tenant to the container explicitly:
```php
app()->instance('tenant', $tenant);
```

### Stack

- **Backend:** Laravel 12, PHP 8.2+, PostgreSQL (production), SQLite (testing), Sanctum
- **Frontend:** Vue 3 (Composition API), Vite, Pinia, Vue Router, Axios, Tailwind CSS v4
- **Docker:** `docker-compose.yml` includes `postgres:16`, `redis:7`, `nginx`, `backend` (PHP-FPM), `frontend` (Vite/Node)
