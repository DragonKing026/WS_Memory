---
tags: [ws-memory, documentation, backend, symfony, layers, api]
---

> Translated from [`docs/08-backend.md`](../08-backend.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# The backend — what each part is for

Status: **working** (2026-09-12). Implemented: health, invitations, accounts,
sign-in, spaces and roles, auditing. Missing: the MCP gateway (TODO-004), the
wiki (TODO-005), publishing from local palaces (TODO-012).

This document describes **every part of the backend and why it exists**. If you
do not know where a new thing belongs, start here.

## Layers and the direction of dependencies

```
Presentation/    ← entry points: HTTP and console. They translate a request into a call.
Application/     ← use cases: "issue an invitation", "accept one".
Domain/          ← rules. NO Symfony, NO Doctrine, NO MemPalace.
Infrastructure/  ← port implementations: Doctrine, HTTP, MemPalace.
Entity/          ← the persistence model (Doctrine). Table mapping, not rules.
```

Dependencies point **inwards only**. `Domain` imports nothing from the other
layers — which is what lets permission rules be tested without a database and
without a container, so the negative tests run on every commit in a fraction of
a second.

The test: *if Doctrine or MemPalace had to be replaced tomorrow, how many files
in `Domain/` would need touching?* The answer must be "zero".

**Why `Entity/` sits apart rather than inside `Domain/`:** Doctrine entities
carry mapping attributes, i.e. they know about the database. Keeping them in
`Domain/` would soil a layer that is meant to be clean. Keeping a separate
domain model alongside the entities would mean hand-copying in both directions —
a cost out of proportion to this project's size. The compromise: entities are
the **persistence model**, and the rules live in `Domain/`.

## File map

### `Domain/` — rules, no framework

| File | Role |
|---|---|
| `Identity/Actor.php` | Who is performing an operation: a person or an agent token. **One type for both**, because REST and MCP call the same services — were identity modelled twice, the two surfaces would eventually differ on permissions, and the difference would surface as a leak rather than an error. |
| `Space/SpaceId.php` | A space identifier as a value object. A string can be confused with another string; this type cannot. |
| `Space/SpaceRole.php` | A role in a space: `reader` / `writer` / `admin`. Ordered by strength, so "at least a writer" is a comparison rather than a list of cases somebody forgets to extend when a fourth role appears. |
| `Space/SpaceMembershipRepository.php` | **Port**: where memberships come from. Declared in the domain, implemented in infrastructure. |
| `Space/SpaceAccessResolver.php` | **The single place that computes permissions.** Every read and write goes through it. It holds no cache — a revoked role stops working at once, not when something expires. |
| `Audit/AuditTrail.php` | **Port**: recording who did what. In the domain, because auditing is a rule of this system rather than an infrastructure convenience (D-016). |
| `Health/*` | The `HealthProbe` port plus `HealthChecker` assembling a report. Monitoring another dependency means adding a class, not editing a controller. |

### `Application/` — use cases

| File | Role |
|---|---|
| `Invitation/IssueInvitation.php` | Issues an invitation. The token is random and the database holds **only its hash**; the plain value is returned once and never stored. |
| `Invitation/AcceptInvitation.php` | Turns an invitation into an account **together with its private space, in one transaction**. A write naming no space lands there (rule 6), so an account without one would break an agent's very first write. |
| `Invitation/IssuedInvitation.php` | The result object carrying the plain token. **Not a service** — excluded from the container. |

### `Infrastructure/` — port adapters

| File | Role |
|---|---|
| `Doctrine/DoctrineSpaceMembershipRepository.php` | Reads memberships **through DBAL, not the ORM**. This query sits on the permission path of every request, and hydrating entities would put the identity map between a revoked role and its effect — exactly the cache the resolver promises not to have. |
| `Doctrine/DoctrineAuditTrail.php` | Writes audit entries. Takes the IP and user agent from the current request rather than from parameters — as parameters, some call sites would forget them, and an entry without provenance answers half the question it exists for. |
| `Doctrine/DatabaseHealthProbe.php` | Probe: does the database respond. |
| `MemPalace/MemPalaceHealthProbe.php` | Probe: does memory respond. Queries `/healthz` with a short timeout — a hanging health check is worse than a negative one. |
| `Security/ActiveAccountChecker.php` | Refuses inactive accounts — at sign-in **and on every subsequent request**. A JWT stays cryptographically valid until it expires, so without this a dismissed person would keep reading the base for the lifetime of their last token. |
| `Security/LoginAuditSubscriber.php` | Sign-in auditing. Hung off security events because the login controller **never executes** — the firewall answers first. |

### `Presentation/` — entry points

| Route / command | File | Note |
|---|---|---|
| `GET /api/health` | `Api/HealthController.php` | Unauthenticated: monitoring carries no token and must not need one. `200` when healthy, `503` when not — Docker reads the code, not the body. |
| `POST /api/login` | `Api/LoginController.php` | **The body never executes.** The route exists because Symfony must resolve `check_path`; the `json_login` firewall answers. |
| `GET /api/me` | `Api/MeController.php` | The frontend's entry into the permission model. The space list comes from `SpaceAccessResolver`, not from a query of its own. |
| `GET /api/spaces`, `GET /api/spaces/{slug}` | `Api/SpaceController.php` | A space outside your permissions answers **byte for byte** like one that does not exist. |
| `POST /api/spaces`, `POST /api/spaces/{slug}/members` | `Api/SpaceAdministrationController.php` | Creating spaces and granting roles. The creator becomes its administrator at once; the `priv_` prefix is reserved; a private space cannot be shared. |
| `POST /api/invitations/accept` | `Api/AcceptInvitationController.php` | Public by necessity — the caller has no account yet. The password policy is enforced here, not in the browser. |
| `ws:user:invite` | `Console/InviteUserCommand.php` | The only route to the first account. It prints the link, because the first invitation is usually issued before the mailer is configured. |

### `Entity/` — the persistence model

| Entity | Design notes |
|---|---|
| `User` | Accounts are **deactivated, never deleted** — revisions and audit entries point at their author, and history that loses its author stops being evidence. `ROLE_USER` is implicit and not stored. |
| `Space` | `Space::privateFor()` creates the private space `priv_<uuid>`. A non-null `palace_namespace` means separate pgvector tables for sensitive spaces. |
| `SpaceMember` | A **composite key** `(space, user)`: two roles for one person in one space are unrepresentable, so "which one wins" cannot be asked. |
| `Invitation` | Only the token hash. `isUsable()` combines "unused" and "unexpired" in one place, so a second entry point cannot forget one of them. |
| `AuditLog` | Append-only. The actor is stored as a **plain identifier, not a foreign key** — deactivating an account never touches the record of what it did. |

## How a request flows

### Signing in

1. `POST /api/login` → the `json_login` firewall intercepts; the controller does
   not run.
2. The `app_users` provider finds the account by e-mail, the hasher verifies the
   password.
3. Success → `LoginSuccessEvent` → `LoginAuditSubscriber` records `user.login`
   and the last-login stamp; Lexik returns a JWT.
4. Failure → `LoginFailureEvent` → a `user.login_failed` entry **with no actor**:
   at that point we have a claimed identity, not a confirmed one, so recording
   it would let anyone forge audit entries using somebody else's address.

An unknown account and a wrong password answer identically — otherwise the login
form would double as a way to check who works here.

### Reading a space

1. The `api` firewall verifies the JWT and loads the user **from the database on
   every request**.
2. The controller builds an `Actor` and asks `SpaceAccessResolver`.
3. The resolver reads roles through DBAL — no cache, so revoking a role takes
   effect immediately.
4. **Permission is checked before existence.** The other order would make the
   response time differ between "absent" and "forbidden", and a slower answer is
   a disclosure too.

### Invitation

`ws:user:invite` or the administrative endpoint → `IssueInvitation` (hash in the
database, plain token once on the way out) → the person opens the link →
`POST /api/invitations/accept` → password validation → `AcceptInvitation`
creates the account, the private space and the membership in one transaction →
an `invitation.accepted` entry.

## Permissions end to end

Four layers, each covered by a negative test:

1. **The firewall** — without a valid JWT there is no access to `/api` beyond
   health, sign-in, invitation acceptance and the contract documentation.
2. **`SpaceAccessResolver`** — the single place computing roles. An agent
   token's scope **narrows and never widens** its owner's permissions.
3. **The controller** — checks permission before existence and returns `404`
   where `403` would disclose that a resource exists.
4. **Auditing** — every state change leaves an entry with the actor, IP and user
   agent.

A global administrator **does not silently read other people's spaces** (D-016).
They may grant themselves a role — and that stays in the log.

## Migrations

Written **by hand**, not generated: with `schema_filter` in place the diff
generator needs DBAL `^4.5` while the stable release is 4.4.4. Mapping is checked
with `doctrine:schema:validate --skip-sync`.

| Migration | Contents |
|---|---|
| `Version20260912000001` | The Messenger queue in `ws`, with a `LISTEN/NOTIFY` trigger. |
| `Version20260912000002` | Accounts, invitations, spaces, roles, the audit log. |

**The `schema_filter` trap** — described in `docs/en/05-deployment.md`. In short:
a `~^ws\.~` filter rejects our own tables, because with `search_path = ws` DBAL
returns them unqualified. The correct pattern **excludes** `palace`.

## Tests

| File | What it checks |
|---|---|
| `Domain/Space/SpaceAccessResolverTest.php` | Permission rules **without a database** — the fastest and the most important. Five of nine cases are negative. |
| `Application/InvitationFlowTest.php` | The whole invitation flow against a real database: the private space, a single-use token, an expired one, an unknown one, the absence of the plain token in storage, the audit entry. |
| `Api/AuthenticationTest.php` | Sign-in, `/api/me`, identical refusal for a wrong password and an unknown account, auditing. |
| `Api/SpaceAccessTest.php` | `404` instead of `403`, identical responses, a list containing only your own spaces, the immediate effect of revoking a role. |
| `Api/SpaceAdministrationTest.php` | Who **cannot**: a writer does not promote themselves, a stranger gets `404`, a private space cannot be shared. |

Running them: `make test` (prepares the test database and runs PHPUnit).

**Why negative tests matter more here than positive ones:** a permission bug is
silent. Nothing throws, nothing appears in a log — content simply reaches
somebody who should not see it.

## How to add something new

**A new endpoint:** a class in `Presentation/Api/` with a `#[Route]` attribute.
Logic goes into `Application/`, not into the controller. Permissions **always**
through `SpaceAccessResolver`.

**A new health probe:** a class implementing `Domain\Health\HealthProbe`. The
container tag picks it up automatically — the controller stays untouched.

**A new permission rule:** in `SpaceAccessResolver` only, with a negative test.
A second way of computing permissions is a second way of getting them wrong.

**A new migration:** by hand in `migrations/`, named `VersionYYYYMMDDNNNNNN`.
The `ws` schema only — we never write to `palace` (D-004).

**A new dependency:** a `D-0xx` decision in `docs/06-decyzje.md` first.

## Configuration

| File | What it sets |
|---|---|
| `config/services.yaml` | Autowiring, the health probe tag, the MemPalace URL, the public URL. A `when@test` block exposes three services to the tests. |
| `config/packages/doctrine.yaml` | The connection, the `schema_filter` hiding `palace`, entity mapping. |
| `config/packages/security.yaml` | Firewalls: health unsecured, `json_login`, JWT for `/api` and `/mcp`. Lowered hashing cost in tests. |
| `config/packages/messenger.yaml` | The queue in the database, `auto_setup: false` — the table comes from a migration. |
| `config/packages/api_platform.yaml` | The contract at `/api/docs.json`, **Swagger UI disabled** (it needs Twig, and the backend renders no interface). |

## Known limitations

- **No token refresh.** `gesdinet/jwt-refresh-token-bundle` does not support
  Symfony 8 yet (it requires `symfony/console ^7`). Until a compatible release
  appears, a token expires and one has to sign in again.
- **No invitation e-mails** — the link has to be passed on by hand.
