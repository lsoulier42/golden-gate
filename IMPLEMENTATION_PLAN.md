# Golden Gate — MVP implementation plan

> Experimental LLM gateway built with Symfony AI.
>
> **Golden Gate** is the bridge between client applications and multiple LLM providers: one API, centralized access control, quotas, budgets and usage tracking.

## 1. MVP goal

Build a small but usable LLM gateway inspired by the core use case of LiteLLM, without trying to reproduce its full feature set.

A client application must be able to:

1. authenticate with a Golden Gate API key;
2. call a stable, provider-agnostic endpoint;
3. select one of the models exposed by Golden Gate;
4. have the request routed to the configured provider through Symfony AI;
5. be rejected when its key, model access, quota or budget does not allow the request;
6. have token usage and estimated cost recorded;
7. let an administrator inspect usage and manage access from the React UI.

The MVP is successful when the same client can switch between at least **two providers** by changing only the requested Golden Gate model.

## 2. Starting point

Initialize this repository from **lsoulier42/react-symfony-starter** and preserve its conventions instead of creating a new architecture.

Existing stack to keep:

- PHP 8.5 / Symfony 8.1
- API Platform 4
- Doctrine ORM + PostgreSQL
- JWT authentication for the administration application
- React 19 + TypeScript + Vite
- Tailwind CSS 4
- TanStack Query
- Docker Compose with PHP-FPM, nginx, PostgreSQL and frontend
- existing user/profile/admin foundations
- existing PHPUnit, PHPStan and frontend lint/build tooling

Add Symfony AI on top of this stack.

The product has **two authentication contexts** that must remain distinct:

- **administration UI/API**: existing user + JWT authentication from the starter;
- **LLM gateway**: dedicated API keys sent by client applications.

Do not reuse user JWTs as gateway credentials.

## 3. MVP scope

### Included

- provider configuration;
- model registry;
- OpenAI-style chat completion endpoint;
- Golden Gate API keys;
- per-key model permissions;
- monthly request/token/budget limits;
- usage and estimated cost tracking;
- basic administration dashboard;
- provider/model management through configuration;
- structured errors;
- tests around authentication, routing and limits.

### Explicitly out of scope

Do not implement these before the MVP works end-to-end:

- embeddings;
- image/audio generation;
- assistants/threads API;
- MCP;
- semantic caching;
- automatic provider fallback;
- load balancing;
- advanced routing based on latency/cost;
- teams/organizations/RBAC beyond the starter's admin role;
- billing/payment;
- distributed rate limiting;
- full LiteLLM API compatibility;
- provider secrets editable from the browser.

## 4. Target architecture

```text
                         GOLDEN GATE

 Client application
        |
        | Authorization: Bearer gg_...
        v
 +-------------------------------+
 | POST /v1/chat/completions     |
 +---------------+---------------+
                 |
                 v
         API key authentication
                 |
                 v
         Access / quota check
                 |
                 v
           Model Registry
                 |
                 v
          Model Gateway
                 |
                 v
            Symfony AI
                 |
       +---------+---------+
       |                   |
       v                   v
 Provider A            Provider B
       |                   |
       +---------+---------+
                 |
                 v
        Usage + cost record
```

The HTTP controller should not contain provider-specific logic. Provider resolution belongs behind application services.

## 5. Domain model

### ApiKey

Represents credentials used by an application consuming the gateway.

Suggested fields:

- `id` UUID;
- `name`;
- `keyPrefix` for display/search;
- `keyHash`;
- `enabled`;
- `createdAt`;
- `lastUsedAt`;
- optional `expiresAt`;
- `monthlyRequestLimit` nullable;
- `monthlyTokenLimit` nullable;
- `monthlyBudgetUsd` nullable.

Generate a high-entropy secret such as `gg_<random>`.

**Never persist or log the clear-text key.** Return it only once at creation and store a cryptographic hash.

### Model

Represents the stable public model exposed by Golden Gate.

Suggested fields/configuration:

- public alias, e.g. `mistral-small`;
- provider identifier;
- upstream model identifier;
- enabled flag;
- input price per million tokens;
- output price per million tokens.

For the MVP, the model registry may be configuration-driven rather than editable in the database. This keeps provider credentials and infrastructure configuration out of the admin UI.

Example concept:

```yaml
golden_gate:
  models:
    mistral-small:
      provider: ovhcloud
      upstream_model: Mistral-Small-3.2-24B-Instruct-2506

    qwen-32b:
      provider: ovhcloud
      upstream_model: Qwen3-32B
```

Add a second provider/model during implementation to prove that the abstraction is genuinely multi-provider.

### ApiKeyModelAccess

Allow an API key to access either:

- every enabled model; or
- an explicit allow-list.

Choose the simplest representation compatible with Doctrine. Do not introduce a generic policy engine.

### UsageRecord

One immutable record per gateway request.

Suggested fields:

- `id` UUID;
- API key relation;
- public model alias;
- provider;
- upstream model;
- timestamp;
- status;
- input tokens;
- output tokens;
- total tokens;
- estimated cost USD;
- latency in milliseconds;
- provider request ID when available;
- error category when failed.

Do **not** persist prompt or completion contents in the MVP. Usage metadata is enough for observability and avoids unexpectedly storing client data.

## 6. Phase 0 — Import the starter

Populate `golden-gate` from `react-symfony-starter`.

Tasks:

- copy the starter application while keeping Golden Gate's own Git history;
- rename starter-specific frontend/package metadata;
- update README/project branding;
- verify `docker compose up`;
- verify migrations;
- verify backend tests;
- verify `npm run build` and lint;
- create an initial administrator through the existing starter mechanism.

Do not redesign the starter before the gateway works.

**Acceptance criterion:** Golden Gate boots locally with the existing authentication/admin shell intact.

## 7. Phase 1 — Symfony AI and provider abstraction

Install the current Symfony AI packages required for chat/text generation and the selected provider bridges.

Create an application-level abstraction, for example:

```php
interface ModelGatewayInterface
{
    public function complete(ChatCompletionRequest $request): ChatCompletionResult;
}
```

Responsibilities:

- resolve public model alias;
- resolve provider adapter;
- convert the internal request to Symfony AI;
- execute the model;
- normalize the provider response;
- expose token usage/provider metadata when available.

Suggested structure:

```text
src/
  AI/
    Gateway/
      ModelGatewayInterface.php
      SymfonyAiModelGateway.php
    Model/
      ModelDefinition.php
      ModelRegistry.php
    DTO/
      ChatCompletionRequest.php
      ChatCompletionResult.php
```

Provider credentials must come from environment/secrets, never Doctrine.

Start with the OVHcloud models already used in the LiteLLM experiment:

- `Mistral-Small-3.2-24B-Instruct-2506`;
- `Qwen3-32B`.

Then add one model from a second supported provider to validate the architecture.

**Acceptance criterion:** an internal integration test/service can send the same normalized request through two provider configurations.

## 8. Phase 2 — Public gateway API

Implement:

```http
POST /v1/chat/completions
Authorization: Bearer gg_xxxxxxxxx
Content-Type: application/json
```

Support the useful OpenAI-compatible subset only:

```json
{
  "model": "mistral-small",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Hello"}
  ],
  "temperature": 0.7,
  "max_tokens": 500
}
```

Return an OpenAI-style response containing at minimum:

- `id`;
- `object`;
- `created`;
- public `model`;
- `choices`;
- `usage.prompt_tokens`;
- `usage.completion_tokens`;
- `usage.total_tokens`.

Also expose:

```http
GET /v1/models
```

It returns only models accessible to the current API key.

Do not promise full OpenAI compatibility. Document the supported subset.

Streaming can be postponed until the synchronous path is solid; if easy with the selected Symfony AI transport, it can be the final MVP enhancement rather than a prerequisite.

## 9. Phase 3 — API key authentication

Implement a dedicated stateless security mechanism for `/v1/*`.

Flow:

1. read Bearer token;
2. validate `gg_` format;
3. locate candidate by prefix;
4. verify its hash;
5. reject disabled/expired keys;
6. expose the authenticated API key to the request context;
7. update `lastUsedAt` without exposing the secret.

Expected failures:

- missing key → 401;
- invalid key → 401;
- disabled/expired key → 401;
- forbidden model → 403.

Keep `/api/*` on the starter's JWT firewall and `/v1/*` on the gateway API-key firewall.

## 10. Phase 4 — Quotas and budgets

Before provider execution, evaluate limits for the current calendar month.

MVP limits:

- request count;
- total tokens;
- estimated USD spend.

A request must be blocked when an already-consumed quota has been reached.

For token/cost limits, exact future consumption cannot always be known before inference. Therefore:

- reject when the existing monthly consumption has already reached the limit;
- optionally use `max_tokens` for a conservative preflight estimate;
- record actual usage after the provider response;
- document that the MVP can overshoot a limit by one request.

Return HTTP `429` for exhausted usage limits with a stable machine-readable error code.

Centralize this in a service such as:

```text
UsageLimitChecker
  -> ApiKey
  -> monthly UsageRecord aggregate
  -> LimitDecision
```

Avoid embedding quota SQL in the controller.

## 11. Phase 5 — Usage and cost tracking

For every attempted provider call:

1. start latency timer;
2. execute provider request;
3. retrieve token usage when available;
4. calculate estimated cost from the model definition;
5. persist a `UsageRecord`;
6. persist normalized failure metadata on provider errors.

Cost formula:

```text
(input_tokens / 1,000,000 * input_price)
+
(output_tokens / 1,000,000 * output_price)
```

Use decimal/numeric database types for money, not floating-point persistence.

Never store:

- provider API secrets;
- Golden Gate clear-text API keys;
- prompts;
- completions.

Add structured application logs with request ID, API key ID/prefix, model alias, provider, latency, token counts and status.

## 12. Phase 6 — React administration UI

Reuse the starter's authenticated React application and admin patterns.

Create a compact Golden Gate dashboard.

### Dashboard

Show:

- requests this month;
- tokens this month;
- estimated spend this month;
- success/error counts;
- usage by model;
- recent requests.

Simple cards/tables are enough. Avoid a charting dependency unless it materially improves the MVP.

### API keys

Admin can:

- list keys;
- create a key;
- see the clear-text key **once** after creation;
- copy it;
- enable/disable it;
- configure monthly request/token/budget limits;
- configure accessible models;
- revoke/delete a key.

List view displays only prefix/metadata, never the secret.

### Models

Read-only screen showing:

- public alias;
- provider;
- upstream model;
- enabled state;
- configured prices.

Configuration remains server-side for MVP.

### Usage

Paginated table with filters:

- API key;
- model;
- provider;
- success/error;
- date range.

Do not expose prompt/completion columns because they are deliberately not stored.

## 13. API organization

Keep administration endpoints under the existing `/api` namespace.

Suggested surface:

```text
# Gateway
GET    /v1/models
POST   /v1/chat/completions

# Administration
GET    /api/gateway/dashboard
GET    /api/gateway/api-keys
POST   /api/gateway/api-keys
PATCH  /api/gateway/api-keys/{id}
DELETE /api/gateway/api-keys/{id}
GET    /api/gateway/models
GET    /api/gateway/usage
```

Use API Platform resources where it remains natural for CRUD/read operations. Prefer explicit controllers/providers/processors for operations such as API-key creation where returning a one-time secret requires special handling.

## 14. Error contract

Return a stable JSON error shape for `/v1/*`, inspired by OpenAI but owned by Golden Gate:

```json
{
  "error": {
    "message": "Monthly budget exhausted.",
    "type": "quota_error",
    "code": "monthly_budget_exceeded"
  }
}
```

Minimum codes:

- `invalid_api_key`;
- `api_key_disabled`;
- `api_key_expired`;
- `model_not_found`;
- `model_not_allowed`;
- `monthly_request_limit_exceeded`;
- `monthly_token_limit_exceeded`;
- `monthly_budget_exceeded`;
- `provider_error`;
- `provider_timeout`.

Do not leak raw provider exceptions or credentials.

## 15. Testing strategy

### Unit tests

Cover:

- API-key generation/hash verification;
- model registry;
- access decisions;
- quota decisions;
- cost calculation;
- provider response normalization.

### Functional tests

Cover:

- unauthenticated `/v1` request;
- invalid/disabled API key;
- inaccessible model;
- valid completion;
- `GET /v1/models`;
- each quota type;
- provider failure normalization;
- creation/revocation of an API key through admin endpoints.

Mock provider calls in the normal test suite. Keep optional real-provider smoke tests separate and opt-in so CI never spends API credits unexpectedly.

### Existing quality gates

Keep and run:

- PHPUnit;
- PHPStan;
- PHP_CodeSniffer if already configured by the starter;
- frontend ESLint;
- TypeScript/Vite production build.

## 16. Security requirements

Treat the gateway as an internet-facing credential broker.

Required for MVP:

- provider secrets only in environment/Symfony secrets;
- hashed Golden Gate API keys;
- clear-text key displayed only once;
- no prompts/completions in DB or normal logs;
- no secrets in exceptions;
- request body size limit;
- provider timeout;
- strict validation of accepted chat parameters;
- admin endpoints protected by existing JWT/admin authorization;
- `/v1` protected independently by API keys;
- CORS kept restrictive for administration UI;
- rate limiting per API key as a basic abuse guard.

Symfony RateLimiter may provide the simple single-instance MVP implementation. Distributed limiting can wait.

## 17. Suggested implementation order

Implement vertical slices rather than building every entity first.

### Slice A — first proxied completion

- import starter;
- install Symfony AI;
- configure one provider;
- implement model registry;
- implement internal gateway;
- hard-coded/dev-only call proves provider execution.

### Slice B — usable gateway

- `ApiKey`;
- API-key authenticator;
- `POST /v1/chat/completions`;
- `GET /v1/models`;
- normalized errors;
- second provider.

At this point Golden Gate is already usable by an external application.

### Slice C — controlled gateway

- model permissions;
- `UsageRecord`;
- token accounting;
- pricing;
- monthly quotas;
- budget enforcement;
- rate limiting.

### Slice D — administration

- API-key CRUD;
- one-time secret UX;
- dashboard;
- usage table;
- models screen.

### Slice E — hardening

- complete functional tests;
- provider timeout/error cases;
- structured logs;
- documentation;
- example curl requests;
- clean install verification.

## 18. MVP definition of done

The MVP is done when all of the following are demonstrable:

1. project starts from a clean clone using the starter's Docker workflow;
2. an admin can log into the React UI;
3. an admin can generate a Golden Gate API key;
4. the raw secret is shown once and never recoverable afterward;
5. a client can call `POST /v1/chat/completions` using that key;
6. a public model alias is resolved to its configured provider/model;
7. at least two different providers work through the same endpoint contract;
8. model access can be restricted per API key;
9. requests/tokens/costs are recorded;
10. request, token and USD monthly limits can block usage;
11. the dashboard exposes current consumption without storing prompt contents;
12. automated tests cover authentication, routing, quotas and cost calculation;
13. no provider credential or raw Golden Gate key appears in the database/logs;
14. README documents setup and at least one OpenAI-style client/curl example.

## 19. Post-MVP candidates

Only after the definition of done:

- streaming/SSE if not included in MVP;
- embeddings endpoint;
- provider fallback;
- weighted/load-balanced deployments;
- latency-aware or cost-aware routing;
- virtual model aliases with multiple deployments;
- Redis-backed rate limits/counters;
- daily limits;
- per-model budgets;
- custom pricing history;
- usage export;
- Prometheus/OpenTelemetry;
- webhook alerts at budget thresholds;
- OpenAI SDK compatibility tests;
- organization/project hierarchy;
- configurable prompt retention/redaction;
- semantic cache.

The architectural rule should remain: **Golden Gate owns policy and the public contract; Symfony AI owns provider integration.**
