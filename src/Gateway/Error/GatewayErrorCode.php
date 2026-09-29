<?php

namespace App\Gateway\Error;

/**
 * Stable machine-readable error codes of the public gateway contract.
 *
 * They are part of the API: clients branch on them, so an existing code must never
 * change meaning. The list is the minimum required by the MVP error contract.
 */
enum GatewayErrorCode: string
{
    case InvalidApiKey = 'invalid_api_key';
    case ApiKeyDisabled = 'api_key_disabled';
    case ApiKeyExpired = 'api_key_expired';
    case ModelNotFound = 'model_not_found';
    case ModelNotAllowed = 'model_not_allowed';
    case MonthlyRequestLimitExceeded = 'monthly_request_limit_exceeded';
    case MonthlyTokenLimitExceeded = 'monthly_token_limit_exceeded';
    case MonthlyBudgetExceeded = 'monthly_budget_exceeded';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case InvalidRequest = 'invalid_request_error';
    case RequestTooLarge = 'request_too_large';
    case ProviderError = 'provider_error';
    case ProviderTimeout = 'provider_timeout';

    /**
     * Error `type` grouping reported next to the code, in the OpenAI style.
     */
    public function type(): string
    {
        return match ($this) {
            self::InvalidApiKey, self::ApiKeyDisabled, self::ApiKeyExpired => 'authentication_error',
            self::ModelNotFound, self::InvalidRequest, self::RequestTooLarge => 'invalid_request_error',
            self::ModelNotAllowed => 'permission_error',
            self::MonthlyRequestLimitExceeded,
            self::MonthlyTokenLimitExceeded,
            self::MonthlyBudgetExceeded,
            self::RateLimitExceeded => 'quota_error',
            self::ProviderError, self::ProviderTimeout => 'provider_error',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidApiKey, self::ApiKeyDisabled, self::ApiKeyExpired => 401,
            self::ModelNotAllowed => 403,
            self::ModelNotFound, self::InvalidRequest => 400,
            self::RequestTooLarge => 413,
            self::MonthlyRequestLimitExceeded,
            self::MonthlyTokenLimitExceeded,
            self::MonthlyBudgetExceeded,
            self::RateLimitExceeded => 429,
            self::ProviderTimeout => 504,
            self::ProviderError => 502,
        };
    }

    /**
     * Whether the failure was caused by the caller (vs. by us or the provider).
     */
    public function isClientFault(): bool
    {
        return \in_array($this->httpStatus(), [400, 401, 403, 404, 429], true);
    }
}
