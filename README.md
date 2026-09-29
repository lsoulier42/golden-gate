<h1 align="center">Golden Gate</h1>

<p align="center">
  <a href="https://www.php.net/releases/8.5/en.php"><img alt="PHP 8.5" src="https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white" /></a>
  <a href="https://frankenphp.dev"><img alt="FrankenPHP" src="https://img.shields.io/badge/FrankenPHP-1.12-0B5C6B?style=flat-square" /></a>
  <a href="https://symfony.com/releases/8.1"><img alt="Symfony 8.1" src="https://img.shields.io/badge/Symfony-8.1-000000?style=flat-square&logo=symfony&logoColor=white" /></a>
  <a href="https://symfony.com/doc/current/ai/index.html"><img alt="Symfony AI" src="https://img.shields.io/badge/Symfony%20AI-0.14-6c8cff?style=flat-square" /></a>
  <a href="https://api-platform.com"><img alt="API Platform 4" src="https://img.shields.io/badge/API%20Platform-4-0e83cd?style=flat-square" /></a>
  <a href="https://react.dev"><img alt="React 19" src="https://img.shields.io/badge/React-19-61DAFB?style=flat-square&logo=react&logoColor=white" /></a>
  <a href="https://www.postgresql.org/"><img alt="PostgreSQL 18" src="https://img.shields.io/badge/PostgreSQL-18-336791?style=flat-square&logo=postgresql&logoColor=white" /></a>
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/License-MIT-2ea44f?style=flat-square" /></a>
</p>

<p align="center">
  An experimental <strong>LLM gateway</strong>: one provider-agnostic API in front of several
  LLM providers, with API keys, per-key model permissions, monthly quotas, budgets
  and usage tracking — plus a React administration UI.
</p>

---

## 📚 Table of contents

- [What it does](#-what-it-does)
- [Architecture](#-architecture)
- [Prerequisites](#-prerequisites)
- [Quick start](#-quick-start)
- [Configuring providers and models](#-configuring-providers-and-models)
- [Using the gateway](#-using-the-gateway)
- [Error contract](#-error-contract)
- [Limits, budgets and rate limiting](#-limits-budgets-and-rate-limiting)
- [Administration UI](#-administration-ui)
- [Administration API](#-administration-api)
- [What is deliberately not stored](#-what-is-deliberately-not-stored)
- [Tests and quality gates](#-tests-and-quality-gates)
- [Project structure](#-project-structure)
- [Commands](#-commands)
- [Security notes](#-security-notes)
- [Out of scope (post-MVP)](#-out-of-scope-post-mvp)
- [License](#-license)

---

## 📖 What it does

Golden Gate sits between client applications and LLM providers:

1. a client authenticates with a **Golden Gate API key** (`Authorization: Bearer gg_…`);
2. it calls a **stable, provider-agnostic endpoint** (`POST /v1/chat/completions`);
3. it selects one of the **models exposed by Golden Gate** (a public alias such as `mistral-small`);
4. the request is **routed to the configured provider** through [Symfony AI](https://symfony.com/doc/current/ai/index.html);
5. it is **refused** when the key, its model permissions, its quota or its budget do not allow it;
6. token usage and the **estimated cost are recorded**;
7. an administrator inspects usage and manages access from the **React UI**.

The point of the gateway is the last line of the pitch: a client switches providers by
changing one string — the model alias — and nothing else.

```
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
         API key authentication          App\Security\ApiKeyAuthenticator
                 |
                 v
         Access / quota check            App\Gateway\ChatCompletionHandler
                 |                       App\Usage\UsageLimitChecker
                 v
           Model Registry                App\AI\Model\ModelRegistry
                 |
                 v
            Model Gateway                App\AI\Gateway\SymfonyAiModelGateway
                 |
                 v
              Symfony AI                 App\AI\Provider\PlatformFactory
                 |
       +---------+---------+
       |                   |
       v                   v
 Provider A            Provider B          OVHcloud AI Endpoints / Mistral AI
       |                   |
       +---------+---------+
                 |
                 v
        Usage + cost record              App\Usage\UsageRecorder
```

**The architectural rule: Golden Gate owns policy and the public contract; Symfony AI
owns provider integration.** No provider-specific type crosses
`ModelGatewayInterface`, and the HTTP controller contains no provider logic.

---

## 🏗 Architecture

| Area | Technology |
|---|---|
| Language / runtime | PHP 8.5 on FrankenPHP 1.12 (Caddy) |
| Framework | Symfony 8.1 |
| LLM integration | Symfony AI 0.14 (`ai-platform`, `ai-generic-platform`, `ai-mistral-platform`) |
| Administration API | API Platform 4 (JSON-LD) |
| Administration auth | Lexik JWT (stateless) |
| Gateway auth | dedicated hashed API keys |
| Database | PostgreSQL 18 (Doctrine ORM 3) |
| Frontend | React 19, TypeScript, Vite, Tailwind CSS 4, TanStack Query |
| Quality | PHPStan level 6, PHP_CodeSniffer PSR-12, PHPUnit 12, ESLint, `tsc` |

Two authentication contexts are kept strictly separate:

- `/api/*` — the administration application, authenticated with **user JWTs**;
- `/v1/*` — the LLM gateway, authenticated with **Golden Gate API keys**.

A JWT is never accepted as a gateway credential, and a gateway key is never accepted
on the administration API. This is enforced by two firewalls and covered by tests.

---

## 📋 Prerequisites

- **Docker** ≥ 24 with Docker Compose v2
- **Make**
- **Node.js** ≥ 22 and **npm** (for the frontend recipes)

No local PHP, Composer or PostgreSQL installation is required.

---

## 🚀 Quick start

```bash
git clone <this-repository> golden-gate
cd golden-gate

# 1. Build the images, install backend + frontend dependencies, start everything,
#    generate the JWT keypair.
make install

# 2. Initialise the database and load the fixtures
#    (admin@example.com / password, user1..3@example.com / password)
make migrate
make fixtures

# 3. Open the administration UI
#    → http://localhost:8082   (log in as admin@example.com / password)
```

Golden Gate listens on **8082** (not 8081) so it can run side by side with the
starter it was bootstrapped from. Ports are set in `.env`.

```bash
make frontend-dev     # Vite dev server with HMR on http://localhost:5174
make frontend-build   # build the SPA; FrankenPHP then serves it on :8082
```

---

## ⚙️ Configuring providers and models

Provider credentials live in the environment; the model registry lives in
`config/packages/golden_gate.yaml`. Neither is editable from the browser.

```dotenv
# .env — defaults are committed, real secrets belong to .env.local (gitignored)
OVHCLOUD_BASE_URL=https://oai.endpoints.kepler.ai.cloud.ovh.net
OVHCLOUD_API_KEY=
MISTRAL_BASE_URL=https://api.mistral.ai
MISTRAL_API_KEY=
GATEWAY_RATE_LIMIT_PER_MINUTE=60
```

```yaml
# config/packages/golden_gate.yaml
golden_gate:
    providers:
        ovhcloud:
            type: generic        # any OpenAI-compatible endpoint
            base_url: '%env(OVHCLOUD_BASE_URL)%'
            api_key: '%env(OVHCLOUD_API_KEY)%'
            timeout: 60
        mistral:
            type: mistral        # dedicated Symfony AI bridge
            base_url: '%env(MISTRAL_BASE_URL)%'
            api_key: '%env(MISTRAL_API_KEY)%'
            timeout: 60

    models:
        mistral-small:
            provider: ovhcloud
            upstream_model: 'Mistral-Small-3.2-24B-Instruct-2506'
            input_price_per_million_usd: 0.10
            output_price_per_million_usd: 0.30
            context_window: 131072
            description: 'Mistral Small 3.2 24B Instruct, served by OVHcloud AI Endpoints.'

        mistral-medium:
            provider: mistral
            upstream_model: 'mistral-medium-latest'
            input_price_per_million_usd: 0.40
            output_price_per_million_usd: 2.00
```

Two providers are configured on purpose: they prove the abstraction is genuinely
multi-provider. `ovhcloud` goes through the generic OpenAI-compatible bridge,
`mistral` through the native Mistral bridge — two different request/response
conversions behind the same public contract.

To add a provider, add an entry under `providers` and point models at it. A model
whose provider does not exist fails the container build with a clear message.

Supported `type` values: `generic` (any OpenAI-compatible chat completions API) and
`mistral`. Supporting a new protocol means adding a `match` arm in
`App\AI\Provider\PlatformFactory`; nothing else changes.

---

## 🌐 Using the gateway

Create an API key in the UI (**Clés API → Nouvelle clé API**), or through the
administration API. The clear-text secret is shown **once**.

### `POST /v1/chat/completions`

```bash
curl http://localhost:8082/v1/chat/completions \
  -H "Authorization: Bearer gg_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "mistral-small",
    "messages": [
      {"role": "system", "content": "You are a helpful assistant."},
      {"role": "user", "content": "Hello"}
    ],
    "temperature": 0.7,
    "max_tokens": 500
  }'
```

```json
{
  "id": "chatcmpl-9f2c1a7b4e8d3f6051ab",
  "object": "chat.completion",
  "created": 1790723345,
  "model": "mistral-small",
  "choices": [
    {
      "index": 0,
      "message": { "role": "assistant", "content": "Hello! How can I help you today?" },
      "finish_reason": "stop"
    }
  ],
  "usage": { "prompt_tokens": 24, "completion_tokens": 11, "total_tokens": 35 }
}
```

`model` in the response is always the **public alias** the client asked for: the
upstream identifier never leaves the gateway.

Supported request fields: `model`, `messages` (`system`/`user`/`assistant`, string
content), `temperature` (0–2), `max_tokens`, `top_p` (0–1), `stop` (string or list).
Unknown fields are ignored; unsupported content parts are refused explicitly rather
than silently dropped.

Streaming, tools, `n`, `response_format`, embeddings, images and audio are **not**
implemented. This is a documented subset of the OpenAI schema, not full
compatibility.

### `GET /v1/models`

Returns only the models the calling key may use:

```bash
curl http://localhost:8082/v1/models -H "Authorization: Bearer gg_…"
```

```json
{
  "object": "list",
  "data": [
    {
      "id": "mistral-small",
      "object": "model",
      "owned_by": "ovhcloud",
      "description": "Mistral Small 3.2 24B Instruct, served by OVHcloud AI Endpoints.",
      "context_window": 131072
    }
  ]
}
```

### Using an OpenAI client library

Any OpenAI-compatible client works by pointing its base URL at the gateway and using
a Golden Gate key as the API key. With the official Python SDK:

```python
from openai import OpenAI

client = OpenAI(base_url="http://localhost:8082/v1", api_key="gg_xxxxxxxx…")

completion = client.chat.completions.create(
    model="mistral-small",                       # switch provider by changing this alias
    messages=[{"role": "user", "content": "Hello"}],
)
print(completion.choices[0].message.content)
print(completion.usage.total_tokens)
```

With Node.js:

```js
import OpenAI from 'openai';

const client = new OpenAI({ baseURL: 'http://localhost:8082/v1', apiKey: 'gg_xxxxxxxx…' });

const completion = await client.chat.completions.create({
  model: 'mistral-medium',
  messages: [{ role: 'user', content: 'Hello' }],
});
console.log(completion.choices[0].message.content);
```

---

## ⛔ Error contract

Every `/v1/*` failure uses the same JSON shape:

```json
{
  "error": {
    "message": "Monthly budget exhausted (0.0500 of 0.0500 USD used this month).",
    "type": "quota_error",
    "code": "monthly_budget_exceeded"
  }
}
```

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_api_key` | 401 | Missing, malformed or unknown key |
| `api_key_disabled` | 401 | The key was disabled by an administrator |
| `api_key_expired` | 401 | The key is past its expiry date |
| `model_not_found` | 400 | Unknown or disabled model alias |
| `model_not_allowed` | 403 | The key is not allowed to use this model |
| `invalid_request_error` | 400 | Malformed or unsupported payload |
| `request_too_large` | 413 | Body above 1 MB |
| `monthly_request_limit_exceeded` | 429 | Monthly request quota reached |
| `monthly_token_limit_exceeded` | 429 | Monthly token quota reached |
| `monthly_budget_exceeded` | 429 | Monthly USD budget reached |
| `rate_limit_exceeded` | 429 | Per-key request rate exceeded |
| `provider_error` | 502 | The provider failed the request |
| `provider_timeout` | 504 | The provider did not answer in time |

Provider failures are normalized into `provider_error` / `provider_timeout`; the finer
cause (`provider_rate_limited`, `provider_server_error`,
`provider_authentication_failed`, …) is recorded on the usage record for operators.
Raw provider exceptions and credentials are never returned to the client.

---

## 📊 Limits, budgets and rate limiting

Limits are **per API key** and evaluated over the **current calendar month (UTC)**
before the provider is called:

| Limit | Column | Behaviour |
|---|---|---|
| Requests | `monthlyRequestLimit` | Refused when the month already reached the limit |
| Tokens | `monthlyTokenLimit` | Refused when the month already reached the limit |
| Budget | `monthlyBudgetUsd` | Refused when the estimated spend already reached the limit |

Two documented properties of the MVP:

- **A limit can be overshot by one request.** The gateway does not know what a
  request will cost before running it, so the request that pushes a counter past the
  ceiling is still served. `max_tokens` is deliberately not used as a preflight
  estimate: it would reject requests that would have fit.
- **A refused request consumes nothing.** Quota, permission and rate-limit refusals
  are logged but not recorded as usage, so a client cannot be billed for a call that
  never reached a provider.

Independently, each key has a **burst rate limit** (`GATEWAY_RATE_LIMIT_PER_MINUTE`,
60/minute by default) implemented with Symfony RateLimiter. It guards against a
runaway client and protects upstream credits. It is cache-backed, so it is
single-instance for the MVP; a distributed limiter is post-MVP.

---

## 🖥 Administration UI

The React app is the starter's authenticated SPA, extended with a gateway section
(admin only):

- **Tableau de bord** — requests, tokens, estimated spend, success/error counts, usage
  by model and the latest requests, for the current month.
- **Clés API** — list, create (secret shown once with a copy button), enable/disable,
  edit monthly limits, model allow-list and expiry, revoke.
- **Modèles** — read-only registry: public alias, provider, upstream model, enabled
  state and configured prices.
- **Usage** — paginated table with filters on model, provider, API key, status and
  date range.

No charting dependency was added: cards and tables are enough for the MVP, and they
keep the bundle small.

---

## 🔌 Administration API

All under `/api`, JWT-authenticated, `ROLE_ADMIN` required.

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/gateway/dashboard` | Current-month aggregates and recent requests |
| `GET` | `/api/gateway/api-keys` | Paginated keys (prefix and metadata only) |
| `POST` | `/api/gateway/api-keys` | Create a key — **returns the secret once** |
| `GET` | `/api/gateway/api-keys/{id}` | One key |
| `PATCH` | `/api/gateway/api-keys/{id}` | Update name, limits, allow-list, expiry, enabled |
| `DELETE` | `/api/gateway/api-keys/{id}` | Revoke a key |
| `GET` | `/api/gateway/models` | Model registry (read-only) |
| `GET` | `/api/gateway/usage` | Paginated usage records |
| `GET` | `/api/gateway/usage/{id}` | One usage record |

Creating a key:

```bash
TOKEN=$(curl -s -X POST http://localhost:8082/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"password"}' | jq -r .token)

curl -s -X POST http://localhost:8082/api/gateway/api-keys \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "Reporting job",
    "monthlyRequestLimit": 1000,
    "monthlyTokenLimit": 2000000,
    "monthlyBudgetUsd": 5.00,
    "allowedModels": ["mistral-small"]
  }' | jq
```

```json
{
  "id": 1,
  "uuid": "ed2f463f-1030-4dd5-acc5-a19a1b610d4b",
  "name": "Reporting job",
  "keyPrefix": "gg_14e329030a17",
  "enabled": true,
  "monthlyRequestLimit": 1000,
  "monthlyTokenLimit": 2000000,
  "monthlyBudgetUsd": 5.0,
  "allowedModels": ["mistral-small"],
  "key": "gg_14e329030a17d80630b0ac36af36b7e645c1e962f293a310"
}
```

`key` is present **only** in this response. No other endpoint can return it, and the
database only holds its SHA-256 digest.

Usage filters (API Platform query parameters):

```bash
curl -s "http://localhost:8082/api/gateway/usage?status=error&provider=ovhcloud\
&modelAlias=mistral-small&createdAt[after]=2026-09-01&itemsPerPage=50" \
  -H "Authorization: Bearer $TOKEN" | jq
```

---

## 🔒 What is deliberately not stored

For every request the gateway persists metadata only. The following are **never**
written to the database nor to the logs:

- upstream provider credentials;
- clear-text Golden Gate API keys (only a SHA-256 digest and a display prefix);
- prompt contents;
- completion contents.

A usage record holds: request id, API key prefix, model alias, provider, upstream
model, timestamp, status, error category, token counts, estimated cost, latency and
the provider's request id. The `UsageRecordingTest` asserts that no prompt content
can appear in the persisted row.

Structured logs carry the same identifiers and counters — never message content.

---

## 🧪 Tests and quality gates

```bash
make test          # PHPUnit (unit + functional)
make phpstan       # PHPStan level 6
make cs            # PHP_CodeSniffer (PSR-12)
make frontend-lint # ESLint
make frontend-build # tsc --noEmit + vite build
```

The suite covers, among others:

- API-key generation, hashing and verification;
- model registry resolution and access decisions;
- the same normalized request served through **two providers**;
- unauthenticated, invalid, disabled and expired keys;
- inaccessible and unknown models;
- a valid completion, `GET /v1/models`, and their OpenAI-shaped payloads;
- each quota type (requests, tokens, budget) plus the per-key rate limit;
- provider failure and timeout normalization, and that no credential leaks into a
  response;
- usage recording, cost estimation and the absence of prompt contents;
- creation, update and revocation of keys through the admin API, including the
  one-time secret property;
- dashboard, models and usage administration endpoints.

**Provider calls are always mocked.** The test environment installs
`App\Tests\Http\ProviderMockResponseFactory` as the global
`framework.http_client.mock_response_factory`, so the suite can never reach a real
LLM API — and a test that forgets to queue an answer fails loudly instead of
silently spending credits. There is therefore no opt-in real-provider smoke test in
the suite; verify a live provider with the `curl` example above.

---

## 🗂 Project structure

```
src/
├── AI/                          # Symfony AI integration — no gateway policy
│   ├── Dto/                     # ChatCompletionRequest/Result, ChatMessage, usage
│   ├── Exception/
│   ├── Gateway/                 # ModelGatewayInterface + SymfonyAiModelGateway
│   ├── Model/                   # ModelDefinition, ModelRegistry
│   └── Provider/                # ProviderDefinition, ProviderRegistry, PlatformFactory
├── ApiKey/                      # ApiKeyGenerator (creation, prefix, hashing)
├── Controller/
│   ├── Gateway/                 # /v1 controllers + admin gateway controllers
│   ├── MeController.php
│   └── RegistrationController.php
├── DependencyInjection/         # `golden_gate` configuration tree + extension
├── Dto/                         # Validated HTTP inputs
├── Entity/                      # User, ApiKey, UsageRecord
├── Gateway/                     # Gateway application layer
│   ├── ChatCompletionHandler.php # resolve → check → call → record
│   ├── Error/                   # error codes, error response, GatewayException
│   ├── Http/                    # response factory, rate limiter
│   └── Listener/                # exception mapping, rate limit, body size, lastUsedAt
├── Money/                       # UsdAmount (exact integer micro-USD)
├── Repository/
├── Security/                    # ApiKeyAuthenticator, GatewayPrincipal, UserChecker
└── Usage/                       # UsageRecorder, UsageLimitChecker, UsageDashboard

frontend/src/
├── api/gateway.ts               # typed gateway admin client
├── pages/admin/
│   ├── DashboardPage.tsx
│   ├── ApiKeysPage.tsx
│   ├── ModelsPage.tsx
│   └── UsagePage.tsx
└── ...

config/packages/golden_gate.yaml # providers + model registry
docs/gateway.md                  # design notes and decisions
```

---

## 🛠 Commands

| Command | Description |
|---|---|
| `make install` | Build images, install dependencies, start, generate JWT keys |
| `make start` / `make stop` / `make restart` | Container lifecycle |
| `make connect` | Shell inside the PHP container |
| `make migrate` / `make fixtures` | Migrations / fixtures |
| `make test` / `make phpstan` / `make cs` / `make csfix` | Backend quality gates |
| `make frontend-install` / `frontend-dev` / `frontend-build` / `frontend-lint` | Frontend |
| `make logs` / `make worker-logs` | Follow container logs |
| `make destroy` | Remove containers **and volumes** |

---

## 🔐 Security notes

- Provider secrets come from the environment (`.env.local`, `var/` or Symfony
  secrets) and are never compiled into the container nor exposed by an endpoint.
- Golden Gate keys are `gg_` + 48 hex characters (192 bits) and are stored as a
  SHA-256 digest. A single digest — not a password hash — is enough for a
  high-entropy secret and keeps gateway authentication cheap.
- Authentication looks candidates up by key prefix, then compares digests with
  `hash_equals`, so a wrong key cannot be narrowed down by timing.
- The clear-text key exists only in the creation response.
- Request bodies are limited to 1 MB, in Caddy **and** in the application.
- Provider calls have a configurable timeout (60 s by default).
- Accepted chat parameters are validated strictly; unsupported shapes are refused.
- CORS stays restrictive: the SPA and the API share one origin.
- `/api/*` is protected by the starter's JWT firewall and `ROLE_ADMIN`;
  `/v1/*` by API keys. Neither credential is accepted on the other side.

---

## 🚧 Out of scope (post-MVP)

Deliberately not implemented yet: streaming/SSE, embeddings, image and audio
generation, assistants/threads, MCP, semantic caching, automatic provider fallback,
load balancing, latency- or cost-aware routing, teams/organizations/RBAC beyond the
admin role, billing, distributed rate limiting, full LiteLLM API compatibility, and
provider secrets editable from the browser.

---

## 📄 License

Distributed under the **MIT** license — see the [LICENSE](LICENSE) file.
Copyright © 2023 Louise SOULIER.
