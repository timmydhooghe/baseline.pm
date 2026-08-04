# Baseline

Baseline keeps the commercial position of consultancy engagements honest: versioned rate cards, frozen baselines, change requests and weekly burn — with an auditable trail and a read-only portal for customer stakeholders.

## Stack

- Laravel 13 (PHP 8.4+), PostgreSQL, Redis (queues via Horizon)
- Inertia.js 3 + React 19, Tailwind CSS 4, Vite
- Pest 5, Pint, Larastan (level 8)
- Session auth via the starter kit (Fortify under the hood). Public registration is disabled — members join by owner invitation (WEBAPP-16). A separate `stakeholder` guard backs the customer portal at `/portal`.

## Local development (no Docker)

Native setup with [Laravel Herd](https://herd.laravel.com) (or any local PHP 8.4+):

1. **Services** — PostgreSQL 14+ and Redis running locally:

   ```sh
   brew install postgresql@16 redis
   brew services start postgresql@16 redis
   createdb baseline -U postgres
   ```

2. **App**:

   ```sh
   cp .env.example .env   # PostgreSQL + Redis are preconfigured; adjust credentials if needed
   composer setup         # install, key:generate, migrate, npm install, build
   php artisan db:seed    # 1 organization + 5 users (one per role)
   ```

3. **Run** — `composer dev` (server, queue, Vite dev server via `artisan dev`), or open the site through Herd and run `npm run dev` alongside. Horizon: `php artisan horizon`, dashboard at `/horizon` (owner role only).

Seeded logins (password `password`): `owner@baseline.test`, `delivery_manager@baseline.test`, `commercial_manager@baseline.test`, `member@baseline.test`, `portfolio_viewer@baseline.test`.

## Quality checks

```sh
composer test    # pint --test, larastan, pest
composer lint    # pint (fixes style)
npm run types:check && npm run lint && npm run format
```

Tests run on an in-memory SQLite database (see `phpunit.xml`); CI (`.github/workflows/tests.yml`) runs the same checks on PHP 8.5 with PostgreSQL and Redis services.

## Conventions

The foundation conventions — multi-tenancy, UUID keys, the `Money` value object, append-only audit logs, immutable snapshots and enum state machines — are documented in [`docs/architecture.md`](docs/architecture.md). Read it before adding domain code.
