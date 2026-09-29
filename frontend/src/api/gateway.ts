import { apiClient } from './client';
import type {
  ApiKeyDto,
  ApiKeyPatchInput,
  ApiKeyWriteInput,
  ApiKeysCollection,
  CreatedApiKeyDto,
  GatewayDashboardDto,
  GatewayModelsResponse,
  UsageCollection,
} from './types';

/** Administration surface of the LLM gateway (ROLE_ADMIN, JWT-authenticated). */

export async function getGatewayDashboard(): Promise<GatewayDashboardDto> {
  const { data } = await apiClient.get<GatewayDashboardDto>('/gateway/dashboard');
  return data;
}

export async function getGatewayModels(): Promise<GatewayModelsResponse> {
  const { data } = await apiClient.get<GatewayModelsResponse>('/gateway/models');
  return data;
}

export interface ApiKeyListParams {
  page?: number;
  itemsPerPage?: number;
  name?: string;
  enabled?: boolean;
}

export async function getApiKeys(params: ApiKeyListParams = {}): Promise<ApiKeysCollection> {
  const { data } = await apiClient.get<ApiKeysCollection>('/gateway/api-keys', { params });
  return data;
}

/** Creates a key. The returned `key` is the only time the secret is ever available. */
export async function createApiKey(input: ApiKeyWriteInput): Promise<CreatedApiKeyDto> {
  const { data } = await apiClient.post<CreatedApiKeyDto>('/gateway/api-keys', input);
  return data;
}

export async function patchApiKey(id: number, input: ApiKeyPatchInput): Promise<ApiKeyDto> {
  const { data } = await apiClient.patch<ApiKeyDto>(`/gateway/api-keys/${id}`, input, {
    headers: { 'Content-Type': 'application/merge-patch+json' },
  });
  return data;
}

export async function deleteApiKey(id: number): Promise<void> {
  await apiClient.delete(`/gateway/api-keys/${id}`);
}

export interface UsageListParams {
  page?: number;
  itemsPerPage?: number;
  modelAlias?: string;
  provider?: string;
  status?: 'success' | 'error';
  'apiKey.keyPrefix'?: string;
  'createdAt[after]'?: string;
  'createdAt[before]'?: string;
  order?: Record<string, 'asc' | 'desc'>;
}

export async function getUsage(params: UsageListParams = {}): Promise<UsageCollection> {
  const { data } = await apiClient.get<UsageCollection>('/gateway/usage', { params });
  return data;
}
