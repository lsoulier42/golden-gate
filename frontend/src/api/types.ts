/** API data types mirroring the Symfony (API Platform + custom controllers) responses. */

export interface UserDto {
  '@id': string;
  '@type': string;
  id: number;
  uuid: string;
  email: string;
  firstName: string | null;
  lastName: string | null;
  roles: string[];
  isActive: boolean;
}

export interface UsersCollection {
  '@context': string;
  '@id': string;
  '@type': string;
  totalItems: number;
  member: UserDto[];
  view?: {
    '@id': string;
    '@type': string;
    first: string;
    last: string;
    next?: string;
  };
}

export interface MeDto {
  email: string;
  firstName: string | null;
  lastName: string | null;
  roles: string[];
  uuid: string;
  isActive: boolean;
}

export interface LoginResponse {
  token: string;
}

export interface Violation {
  propertyPath: string;
  message: string;
}

export interface ApiErrorBody {
  message?: string;
  violations?: Violation[];
  code?: number;
}

export type UserPatchInput = Partial<Pick<UserDto, 'isActive' | 'roles' | 'email' | 'firstName' | 'lastName'>>;
/* ---------- Golden Gate gateway ---------- */

export interface ApiKeyDto {
  '@id': string;
  '@type': string;
  id: number;
  uuid: string;
  name: string;
  keyPrefix: string;
  enabled: boolean;
  expiresAt: string | null;
  monthlyRequestLimit: number | null;
  monthlyTokenLimit: number | null;
  monthlyBudgetUsd: number | null;
  /** Omitted by API Platform when the key is not restricted to an allow-list. */
  allowedModels?: string[] | null;
  lastUsedAt: string | null;
  createdAt: string | null;
}

export interface ApiKeysCollection {
  '@context': string;
  '@id': string;
  '@type': string;
  totalItems: number;
  member: ApiKeyDto[];
  view?: {
    '@id': string;
    first: string;
    last: string;
    next?: string;
  };
}

/** Response of POST /api/gateway/api-keys: the clear-text secret is returned once. */
export interface CreatedApiKeyDto extends ApiKeyDto {
  key: string;
}

export interface ApiKeyWriteInput {
  name: string;
  monthlyRequestLimit?: number | null;
  monthlyTokenLimit?: number | null;
  monthlyBudgetUsd?: number | null;
  allowedModels?: string[] | null;
  expiresAt?: string | null;
}

export type ApiKeyPatchInput = Partial<ApiKeyWriteInput & { enabled: boolean }>;

export interface GatewayModelDto {
  alias: string;
  provider: string;
  upstreamModel: string;
  enabled: boolean;
  inputPricePerMillionUsd: number;
  outputPricePerMillionUsd: number;
  description: string;
  contextWindow: number | null;
}

export interface GatewayModelsResponse {
  models: GatewayModelDto[];
}

export interface UsageByModelDto {
  model: string;
  provider: string;
  requests: number;
  tokens: number;
  estimatedCostUsd: number;
}

export interface RecentRequestDto {
  id: number;
  requestId: string;
  createdAt: string | null;
  apiKeyPrefix: string;
  model: string;
  provider: string;
  status: 'success' | 'error';
  errorCategory: string | null;
  promptTokens: number | null;
  completionTokens: number | null;
  totalTokens: number | null;
  estimatedCostUsd: number;
  latencyMs: number | null;
}

export interface GatewayDashboardDto {
  periodStart: string;
  requests: number;
  tokens: number;
  estimatedCostUsd: number;
  successCount: number;
  errorCount: number;
  byModel: UsageByModelDto[];
  recentRequests: RecentRequestDto[];
}

export interface UsageRecordDto {
  '@id': string;
  '@type': string;
  id: number;
  requestId: string;
  createdAt: string | null;
  apiKeyPrefix: string;
  modelAlias: string;
  provider: string;
  upstreamModel: string;
  status: 'success' | 'error';
  errorCategory: string | null;
  promptTokens: number | null;
  completionTokens: number | null;
  totalTokens: number | null;
  estimatedCostUsd: number;
  latencyMs: number | null;
}

export interface UsageCollection {
  '@context': string;
  '@id': string;
  '@type': string;
  totalItems: number;
  member: UsageRecordDto[];
  view?: {
    '@id': string;
    first: string;
    last: string;
    next?: string;
  };
}
