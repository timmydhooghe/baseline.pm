# Architecture conventions

Foundation conventions every feature builds on. They exist so that money is never a float, tenant data never leaks across organizations, and anything commercially binding leaves an immutable trail.

## Multi-tenancy

One PostgreSQL database, row-level tenancy keyed by `organization_id`. `App\Models\Organization` is the tenant root.

- **Every tenant table** carries a `organization_id` foreign UUID and the model uses the `App\Models\Concerns\BelongsToOrganization` trait. The trait registers `App\Models\Scopes\OrganizationScope` (constrains every query to the current organization) and fills `organization_id` automatically on create.
- **The current organization** lives in Laravel's `Context` under the `organization_id` key. `App\Http\Middleware\SetCurrentOrganization` (in the `web` group) resolves it from the authenticated user. Context propagates to queued jobs, so tenant scoping holds in workers too.
- **No context → unscoped queries.** Console commands, seeders and guest requests run without a tenant filter; constrain explicitly there. To cross tenants deliberately, use `Model::withoutGlobalScope(OrganizationScope::class)` and say why in the calling code.
- **`User` is intentionally not scoped**: the auth layer must find users before any organization context exists. Query members via `$organization->users()` or `User::whereBelongsTo($organization)`.

### Roles & authorization

`users.role` is a string column cast to the `App\Enums\UserRole` enum: `owner`, `delivery_manager`, `commercial_manager`, `member`, `portfolio_viewer`. Authorization goes through one policy class per model in `app/Policies` (auto-discovered). Policies check role + same-organization membership; `UserRole::isManager()` groups the three managing roles. No permission tables — roles are the whole model until a real need appears.

### Auth boundaries

- Internal users: session auth on the `web` guard (starter kit / Fortify). Public registration is disabled; the owner invites members by email (`invitations` table, token links under `/invitations/{token}`, acceptance runs unauthenticated and resolves by token). External stakeholders never consume paid seats.
- Customer stakeholders: separate `stakeholder` session guard backed by `App\Models\Stakeholder` (no password — magic-link/signed-URL login lands with the portal work). Portal routes live under `/portal`.

## Money

`App\ValueObjects\Money` — immutable, integer **cents**, EUR by default. **Never floats, never string decimals.**

- Construct with `Money::fromCents(12345)`; arithmetic (`add`, `subtract`, `multiply`, `negate`) returns new instances and refuses mixed currencies.
- Database columns store cents in a `bigInteger` named `{attribute}_cents`. Casting `'unit_cost' => Money::class` (or `Money::class.':USD'`) maps the virtual `unit_cost` attribute onto `unit_cost_cents` via `App\Casts\AsMoney`.
- Formatting (`->format()`, `€ 1.234,56`) is for display only; persist and compute with cents.

## Audit log (append-only)

`App\Models\AuditLog` records who did what to which subject: `organization_id`, nullable `actor_id`, `action`, morphed `subject`, JSON `payload`, `created_at`. The model **throws on update or delete** — corrections are new entries, never edits.

- Give a model the `App\Models\Concerns\RecordsAuditLog` trait and its create/update/delete events are logged automatically (changed attributes plus previous values on update; hidden attributes are never written to payloads).
- Domain actions log explicitly: `AuditLog::record('baseline.approved', $baseline, ['note' => …])`. The actor defaults to the authenticated `web` user, the organization is resolved from the subject.

## Snapshots (immutable)

`App\Models\Snapshot` freezes a subject's state as JSON at a point in time — the pattern behind baselines, change requests, reports and burn weeks. `Snapshot::capture($subject, $payload, $creator)` stores the payload with a canonical SHA-256 hash (key order independent); `verifyIntegrity()` detects tampering. Like audit logs, snapshots refuse updates and deletes at the model level.

Anything derived from a snapshot must read the frozen payload, not the live models — that is the point.

## Enum state machines

Lifecycles (engagement status, CR status, …) are PHP string-backed enums with **transition guard methods on the enum itself** — no state-machine package:

```php
enum EngagementStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active],
            self::Active => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

Models expose a `transitionTo()` method that consults the enum, throws a `LogicException` on illegal moves, and records the transition in the audit log. State columns are strings cast to the enum, like `users.role`.

## Other defaults

- **UUID primary keys** everywhere (`HasUuids`, UUIDv7). Foreign keys via `foreignUuid()`.
- Migrations are per-table and reversible; indexes are added when the table is created.
- Factories exist for every model; seeders build a workable demo org (`php artisan migrate --seed`).
- Larastan runs at level 8 and Pint enforces style — `composer test` is the gate.
