import { useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, Filter, RefreshCw, X } from 'lucide-react';
import { getGatewayModels, getApiKeys, getUsage } from '../../api/gateway';
import { extractApiError } from '../../api/client';
import { Badge, Button, Card, CardHeader, ErrorState, Field, Input, Spinner } from '../../components/ui';
import { dayEndIso, dayStartIso, formatDateTime, formatLatency, formatNumber, formatUsd } from '../../lib/utils';

const ITEMS_PER_PAGE = 30;

interface Filters {
  modelAlias: string;
  provider: string;
  status: '' | 'success' | 'error';
  apiKeyPrefix: string;
  from: string;
  to: string;
}

const EMPTY_FILTERS: Filters = {
  modelAlias: '',
  provider: '',
  status: '',
  apiKeyPrefix: '',
  from: '',
  to: '',
};

/** Paginated, filterable usage table. Prompt and completion contents are not stored. */
export function UsagePage() {
  const [page, setPage] = useState(1);
  const [draft, setDraft] = useState<Filters>(EMPTY_FILTERS);
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);

  const modelsQuery = useQuery({
    queryKey: ['gateway', 'models'],
    queryFn: getGatewayModels,
    staleTime: 5 * 60_000,
  });

  const keysQuery = useQuery({
    queryKey: ['gateway', 'api-keys', { page: 1, search: '' }],
    queryFn: () => getApiKeys({ itemsPerPage: 100 }),
  });

  const usageQuery = useQuery({
    queryKey: ['gateway', 'usage', { page, filters }],
    queryFn: () =>
      getUsage({
        page,
        itemsPerPage: ITEMS_PER_PAGE,
        modelAlias: filters.modelAlias || undefined,
        provider: filters.provider || undefined,
        status: filters.status || undefined,
        'apiKey.keyPrefix': filters.apiKeyPrefix || undefined,
        'createdAt[after]': dayStartIso(filters.from),
        'createdAt[before]': dayEndIso(filters.to),
      }),
  });

  const providers = Array.from(new Set((modelsQuery.data?.models ?? []).map((model) => model.provider)));
  const activeFilterCount = Object.values(filters).filter(Boolean).length;

  const applyFilters = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setFilters(draft);
  };

  const resetFilters = () => {
    setDraft(EMPTY_FILTERS);
    setFilters(EMPTY_FILTERS);
    setPage(1);
  };

  const data = usageQuery.data;
  const totalPages = data ? Math.max(1, Math.ceil(data.totalItems / ITEMS_PER_PAGE)) : 1;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-ink">Usage</h1>
          <p className="mt-1 text-sm text-muted">
            Une ligne par appel au gateway. Les prompts et les réponses ne sont jamais stockés.
          </p>
        </div>
        <div className="flex items-center gap-3">
          {data && <Badge tone="primary">{formatNumber(data.totalItems)} appel(s)</Badge>}
          <Button
            variant="secondary"
            size="sm"
            onClick={() => void usageQuery.refetch()}
            loading={usageQuery.isFetching}
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden />
            Rafraîchir
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader
          title="Filtres"
          aside={
            activeFilterCount > 0 ? (
              <Button variant="ghost" size="sm" onClick={resetFilters}>
                <X className="h-3.5 w-3.5" aria-hidden />
                Réinitialiser ({activeFilterCount})
              </Button>
            ) : undefined
          }
        />
        <form onSubmit={applyFilters} className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Modèle" htmlFor="filter-model">
            <select
              id="filter-model"
              value={draft.modelAlias}
              onChange={(event) => setDraft({ ...draft, modelAlias: event.target.value })}
              className="block w-full rounded-field border border-line bg-surface-2 px-3.5 py-2 text-sm text-ink focus:border-primary/70 focus:outline-none"
            >
              <option value="">Tous</option>
              {(modelsQuery.data?.models ?? []).map((model) => (
                <option key={model.alias} value={model.alias}>
                  {model.alias}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Fournisseur" htmlFor="filter-provider">
            <select
              id="filter-provider"
              value={draft.provider}
              onChange={(event) => setDraft({ ...draft, provider: event.target.value })}
              className="block w-full rounded-field border border-line bg-surface-2 px-3.5 py-2 text-sm text-ink focus:border-primary/70 focus:outline-none"
            >
              <option value="">Tous</option>
              {providers.map((provider) => (
                <option key={provider} value={provider}>
                  {provider}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Clé API" htmlFor="filter-key">
            <select
              id="filter-key"
              value={draft.apiKeyPrefix}
              onChange={(event) => setDraft({ ...draft, apiKeyPrefix: event.target.value })}
              className="block w-full rounded-field border border-line bg-surface-2 px-3.5 py-2 text-sm text-ink focus:border-primary/70 focus:outline-none"
            >
              <option value="">Toutes</option>
              {(keysQuery.data?.member ?? []).map((apiKey) => (
                <option key={apiKey.id} value={apiKey.keyPrefix}>
                  {apiKey.name} ({apiKey.keyPrefix}…)
                </option>
              ))}
            </select>
          </Field>

          <Field label="Statut" htmlFor="filter-status">
            <select
              id="filter-status"
              value={draft.status}
              onChange={(event) => setDraft({ ...draft, status: event.target.value as Filters['status'] })}
              className="block w-full rounded-field border border-line bg-surface-2 px-3.5 py-2 text-sm text-ink focus:border-primary/70 focus:outline-none"
            >
              <option value="">Tous</option>
              <option value="success">Succès</option>
              <option value="error">Erreurs</option>
            </select>
          </Field>

          <Field label="Depuis" htmlFor="filter-from">
            <Input
              id="filter-from"
              type="date"
              value={draft.from}
              onChange={(event) => setDraft({ ...draft, from: event.target.value })}
            />
          </Field>

          <Field label="Jusqu'au" htmlFor="filter-to">
            <Input
              id="filter-to"
              type="date"
              value={draft.to}
              onChange={(event) => setDraft({ ...draft, to: event.target.value })}
            />
          </Field>

          <div className="sm:col-span-2 lg:col-span-3">
            <Button type="submit" size="sm">
              <Filter className="h-3.5 w-3.5" aria-hidden />
              Appliquer
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <CardHeader title="Appels" />

        {usageQuery.isLoading && <Spinner label="Chargement de l'usage…" />}
        {usageQuery.isError && <ErrorState message={extractApiError(usageQuery.error).message} />}

        {data && data.member.length === 0 && (
          <p className="px-5 py-6 text-sm text-muted">Aucun appel ne correspond à ces filtres.</p>
        )}

        {data && data.member.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-line text-xs uppercase tracking-widest text-faint">
                  <th className="px-5 py-3 font-semibold">Date</th>
                  <th className="px-5 py-3 font-semibold">Clé</th>
                  <th className="px-5 py-3 font-semibold">Modèle</th>
                  <th className="px-5 py-3 font-semibold">Fournisseur</th>
                  <th className="px-5 py-3 font-semibold">Statut</th>
                  <th className="px-5 py-3 text-right font-semibold">Entrée</th>
                  <th className="px-5 py-3 text-right font-semibold">Sortie</th>
                  <th className="px-5 py-3 text-right font-semibold">Total</th>
                  <th className="px-5 py-3 text-right font-semibold">Coût</th>
                  <th className="px-5 py-3 text-right font-semibold">Latence</th>
                </tr>
              </thead>
              <tbody>
                {data.member.map((record) => (
                  <tr key={record.id} className="border-b border-line/60 last:border-0">
                    <td className="whitespace-nowrap px-5 py-3 text-muted">{formatDateTime(record.createdAt)}</td>
                    <td className="px-5 py-3 font-mono text-xs text-muted">{record.apiKeyPrefix}</td>
                    <td className="px-5 py-3 font-medium text-ink">{record.modelAlias}</td>
                    <td className="px-5 py-3 text-muted">{record.provider}</td>
                    <td className="px-5 py-3">
                      {record.status === 'success' ? (
                        <Badge tone="success">
                          <CheckCircle2 className="h-3 w-3" aria-hidden />
                          Succès
                        </Badge>
                      ) : (
                        <Badge tone="danger">
                          <AlertTriangle className="h-3 w-3" aria-hidden />
                          {record.errorCategory ?? 'Erreur'}
                        </Badge>
                      )}
                    </td>
                    <td className="px-5 py-3 text-right text-muted">{formatNumber(record.promptTokens)}</td>
                    <td className="px-5 py-3 text-right text-muted">{formatNumber(record.completionTokens)}</td>
                    <td className="px-5 py-3 text-right text-ink">{formatNumber(record.totalTokens)}</td>
                    <td className="px-5 py-3 text-right text-ink">{formatUsd(record.estimatedCostUsd)}</td>
                    <td className="px-5 py-3 text-right text-muted">{formatLatency(record.latencyMs)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {data && totalPages > 1 && (
          <div className="flex items-center justify-between border-t border-line px-5 py-3">
            <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Précédent
            </Button>
            <span className="text-xs text-muted">
              Page {page} / {totalPages}
            </span>
            <Button variant="secondary" size="sm" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>
              Suivant
            </Button>
          </div>
        )}
      </Card>
    </div>
  );
}
