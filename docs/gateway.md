# Golden Gate — design notes

Decisions taken while implementing the MVP, and why. The README documents *how to use*
the gateway; this file documents *why it is built this way*, including the limits
accepted for the MVP.

## 1. Layer boundaries

| Layer | Namespace | Owns |
|---|---|---|
| Public contract | `App\Controller\Gateway`, `App\Gateway\Error` | HTTP shapes, error codes |
| Gateway policy | `App\Gateway`, `App\Usage`, `App\ApiKey`, `App\Security` | keys, permissions, quotas, recording |
| Provider integration | `App\AI` | Symfony AI, providers, model registry |
| Money | `App\Money` | exact amounts |

`ModelGatewayInterface` is the only door between policy and providers:

```php
interface ModelGatewayInterface
{
    public function complete(ChatCompletionRequest $request): ChatCompletionResult;
}
```

Nothing provider-specific crosses it. `ChatCompletionRequest` carries a **public
alias**, never an upstream identifier; `ChatCompletionResult` carries normalized
content, usage, latency and provider metadata. A controller that needed to know which
provider serves a model would be a design failure.

## 2. Provider abstraction: why not the Symfony AI bundle

`symfony/ai-bundle` declares one platform per provider *type*
(`ai.platform.openai`, `ai.platform.mistral`). A gateway needs N providers, and
several may speak the same protocol — two OpenAI-compatible endpoints, for instance.
The bundle's `generic` node is prototype-keyed, but mixing it with the single-instance
nodes and keeping the registry in a second configuration file made the config surface
inconsistent.

Instead, `App\AI\Provider\PlatformFactory` builds platforms from Golden Gate's own
`golden_gate.providers` configuration:

```php
return match ($provider->getType()) {
    ProviderDefinition::TYPE_MISTRAL => MistralPlatformFactory::createPlatform(…),
    default => GenericPlatformFactory::createPlatform(…),
};
```

Consequences:

- one coherent configuration surface (`config/packages/golden_gate.yaml`);
- any number of providers, of any supported type;
- supporting a new protocol is one `match` arm plus one Symfony AI bridge package;
- the Symfony AI Flex recipes for the platform packages are therefore **not used** and
  their generated files are absent. `symfony.lock` still records the recipes, so
  `composer install` does not recreate them. If a recipe is ever updated, Flex will
  regenerate `config/packages/ai_*_platform.yaml`, the container will fail with
  "There is no extension able to load the configuration for ai" — delete the files
  again and keep the `symfony.lock` entries.

Symfony AI still owns all provider integration: factories, contracts, message
normalization, result converters and token-usage extraction.

## 3. Money is never a float

`App\Money\UsdAmount` canonicalizes every amount as an **integer number of
micro-USD** (1 micro-USD = 0.000001 USD).

- Model prices are configured in USD per million tokens and converted to integer
  micro-USD per million tokens at construction.
- `ModelDefinition::estimateCostMicroUsd()` uses integer arithmetic only, rounding
  half up once at the end.
- `usage_record.estimated_cost_micro_usd` and `api_key.monthly_budget_micro_usd` are
  `BIGINT` columns.
- Monthly aggregates use `SUM()` over integers, so a quota decision is exact.
- Floats appear only when an amount is formatted for JSON (`estimatedCostUsd`), which
  is a display concern.

A `decimal(18,8)` column would have been defensible too, but it would have forced
string↔integer conversions in the persistence layer for no benefit. The requirement
was "no floating-point persistence"; an exact integer is the strongest form of that.

## 4. API keys

Layout: `gg_` + 48 hexadecimal characters (24 random bytes, 192 bits).

- **Prefix** (`gg_` + 12 hex characters): stored in clear, indexed, used for display
  and for locating candidates at authentication time.
- **Digest**: SHA-256 of the secret. A password hash (bcrypt/argon2) is unnecessary
  for a 192-bit random secret and would put a deliberate cost on the gateway hot path.
- **Verification**: candidates are fetched by prefix and compared with `hash_equals`,
  so a wrong key cannot be narrowed down by timing. A prefix is a search hint, never a
  proof of identity.
- The clear-text secret exists in exactly one place: the response of
  `POST /api/gateway/api-keys`.

`ApiKey` deliberately does **not** implement `UserInterface`. Instead
`GatewayPrincipal` wraps it, so the entity carries no security plumbing and the
administration resource stays clean. The principal is exposed to controllers through
`#[CurrentUser]`.

## 5. Model permissions

Represented as a nullable JSON column on the key rather than a join table or a policy
engine:

- `null` (or an empty list, normalized to `null`) → every enabled model;
- a non-empty list → an explicit allow-list, stored sorted for stable display.

The rule lives on the entity (`canAccessModel()`), which keeps it testable in
isolation and impossible to bypass from a caller.

## 6. Quota evaluation

`UsageLimitChecker` aggregates the current month in SQL and refuses a request when the
already-consumed amount has reached a limit.

Two consequences are accepted and documented:

1. **One-request overshoot.** The cost of a request is unknown before it runs, so the
   request that crosses a ceiling is still served. `max_tokens` is deliberately *not*
   used as a preflight estimate: it is only an upper bound on output, so using it
   would reject requests that would have fit inside the budget.
2. **Refusals are not billed.** A request refused for quota, permission or rate
   reasons never reached a provider, so it is logged but not recorded as usage.

Month boundaries are computed in UTC from `Psr\Clock\ClockInterface`, so the counter
is stable regardless of the caller's timezone.

## 7. Usage records

One immutable row per **attempted provider call**, written after the answer so it
reflects what the provider actually reported. `promptTokens`/`completionTokens` are
nullable and mean "not reported" rather than zero — a failed call has no token
accounting at all.

The relation to `ApiKey` is nullable with `ON DELETE SET NULL`, and the key *prefix*
is denormalized onto the record, so revoking a key does not erase the history of what
it consumed.

Prompt and completion contents are never persisted. `UsageRecordingTest` asserts this
against the raw database row, not just the API representation.

## 8. Error contract

`GatewayErrorCode` is a single enum that owns, for each code, the `type` grouping, the
HTTP status and whether the fault is the caller's. Two mechanisms consume it:

- `App\Security\Exception\GatewayAuthenticationException` for the firewall, which must
  extend the Security component's `AuthenticationException` for the failure handler to
  run;
- `App\Gateway\Error\GatewayException` for everything after authentication, mapped to
  a response by `GatewayExceptionListener`, which is restricted to `/v1` paths so the
  administration API keeps its own Problem+JSON format.

Provider failures collapse into `provider_error` / `provider_timeout` in the public
contract — the plan's minimum code list — while the finer category
(`provider_rate_limited`, `provider_server_error`, …) is persisted on the usage record
and logged, where operators need it. Provider messages are redacted and truncated
before being returned; the raw message is only logged.

## 9. Testing provider calls

The test environment sets `framework.http_client.mock_response_factory` to
`App\Tests\Http\ProviderMockResponseFactory`. Every outbound HTTP call in the suite is
answered by that queue:

- the suite is hermetic — it cannot reach a real provider, so CI never spends credits;
- a test that forgets to queue an answer gets a loud `500 No provider response was
  queued for …` instead of a silent real call;
- no service has to be replaced in the container, so the wiring under test is the real
  wiring.

The queue is **static** on purpose: a functional test client reboots the kernel between
requests, which would otherwise hand the transport a fresh, empty factory on every
call.

An earlier attempt replaced the `ModelGatewayInterface` service through
`TestContainer::set()`. It silently did nothing, because the compiled
`ChatCompletionHandler` references the concrete gateway and the alias is resolved at
compile time. The current approach avoids container surgery entirely.

## 10. Frontend notes

- The SPA is the starter's app plus a gateway section; it reuses its design tokens,
  primitives (`Card`, `Badge`, `Alert`, `Field`) and TanStack Query patterns.
- Nullable fields are **omitted** by API Platform when they are null (for example
  `allowedModels` on an unrestricted key). Types therefore mark such fields optional
  and the components treat `undefined` and `null` alike. This bit once: a key created
  with "all models" crashed the keys page with
  `Cannot read properties of undefined (reading 'length')`.
- No charting dependency was added: cards and tables cover the MVP dashboard.
- The UI is in French, like the rest of the starter.

## 11. Deliberate non-goals

Streaming, embeddings, images/audio, tools, fallback, load balancing, distributed rate
limiting, teams and billing are out of scope for the MVP. The interfaces leave room
for them (`ChatCompletionRequest`/`Result` are provider-agnostic; `PlatformFactory`
centralizes protocol knowledge), but none of them should be started before the current
behaviour is stable.
