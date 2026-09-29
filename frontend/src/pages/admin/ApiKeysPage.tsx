import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Check,
  Copy,
  KeyRound,
  Pencil,
  Plus,
  Power,
  PowerOff,
  Search,
  ShieldCheck,
  Trash2,
  TriangleAlert,
} from 'lucide-react';
import {
  createApiKey,
  deleteApiKey,
  getApiKeys,
  getGatewayModels,
  patchApiKey,
} from '../../api/gateway';
import { extractApiError } from '../../api/client';
import type { ApiKeyDto, ApiKeyWriteInput, CreatedApiKeyDto } from '../../api/types';
import { Alert, Badge, Button, Card, CardHeader, ErrorState, Field, Input, Spinner } from '../../components/ui';
import { cn, formatDateTime, formatNumber, formatUsd } from '../../lib/utils';

const ITEMS_PER_PAGE = 20;

const EMPTY_FORM = {
  name: '',
  monthlyRequestLimit: '',
  monthlyTokenLimit: '',
  monthlyBudgetUsd: '',
  expiresAt: '',
  allModels: true,
  allowedModels: [] as string[],
};

type FormState = typeof EMPTY_FORM;

/** Shows the clear-text key once, with a copy button, then never again. */
function OneTimeSecretPanel({ created, onDismiss }: { created: CreatedApiKeyDto; onDismiss: () => void }) {
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(created.key);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  return (
    <Card className="border-primary/40">
      <CardHeader
        title="Clé créée — copiez-la maintenant"
        aside={<Badge tone="warning">Affichée une seule fois</Badge>}
      />
      <div className="space-y-4 p-5">
        <Alert kind="success">
          La clé <strong>{created.name}</strong> est active. Ce secret ne sera plus jamais affiché : Golden
          Gate n'en conserve qu'un condensat.
        </Alert>

        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <code className="flex-1 overflow-x-auto rounded-box border border-line bg-app px-3.5 py-2.5 font-mono text-sm text-ink">
            {created.key}
          </code>
          <Button type="button" variant="secondary" onClick={() => void copy()} className="shrink-0">
            {copied ? <Check className="h-4 w-4" aria-hidden /> : <Copy className="h-4 w-4" aria-hidden />}
            {copied ? 'Copiée' : 'Copier'}
          </Button>
        </div>

        <p className="text-xs text-muted">
          Envoyez-la en en-tête : <code className="font-mono">Authorization: Bearer &lt;clé&gt;</code>
        </p>

        <Button type="button" variant="secondary" size="sm" onClick={onDismiss}>
          J'ai copié la clé
        </Button>
      </div>
    </Card>
  );
}

function CreateKeyForm({
  models,
  onCreated,
  onError,
}: {
  models: string[];
  onCreated: (created: CreatedApiKeyDto) => void;
  onError: (message: string | null) => void;
}) {
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: (input: ApiKeyWriteInput) => createApiKey(input),
    onSuccess: (created) => {
      onCreated(created);
      setForm(EMPTY_FORM);
      void queryClient.invalidateQueries({ queryKey: ['gateway', 'api-keys'] });
      void queryClient.invalidateQueries({ queryKey: ['gateway', 'dashboard'] });
    },
    onError: (error) => onError(extractApiError(error).message),
  });

  const toggleModel = (alias: string) => {
    setForm((current) => ({
      ...current,
      allowedModels: current.allowedModels.includes(alias)
        ? current.allowedModels.filter((item) => item !== alias)
        : [...current.allowedModels, alias],
    }));
  };

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    onError(null);

    mutation.mutate({
      name: form.name.trim(),
      monthlyRequestLimit: form.monthlyRequestLimit ? Number(form.monthlyRequestLimit) : null,
      monthlyTokenLimit: form.monthlyTokenLimit ? Number(form.monthlyTokenLimit) : null,
      monthlyBudgetUsd: form.monthlyBudgetUsd ? Number(form.monthlyBudgetUsd) : null,
      expiresAt: form.expiresAt ? new Date(`${form.expiresAt}T00:00:00`).toISOString() : null,
      allowedModels: form.allModels ? null : form.allowedModels,
    });
  };

  return (
    <Card>
      <CardHeader title="Nouvelle clé API" />
      <form onSubmit={handleSubmit} className="space-y-4 p-5">
        <Field label="Nom" htmlFor="key-name" hint="Identifie l'application qui utilisera cette clé.">
          <Input
            id="key-name"
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            placeholder="Application de production"
            required
            minLength={2}
            maxLength={120}
          />
        </Field>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Requêtes / mois" htmlFor="key-requests" hint="Vide = illimité">
            <Input
              id="key-requests"
              type="number"
              min={1}
              value={form.monthlyRequestLimit}
              onChange={(event) => setForm({ ...form, monthlyRequestLimit: event.target.value })}
              placeholder="1000"
            />
          </Field>
          <Field label="Tokens / mois" htmlFor="key-tokens" hint="Vide = illimité">
            <Input
              id="key-tokens"
              type="number"
              min={1}
              value={form.monthlyTokenLimit}
              onChange={(event) => setForm({ ...form, monthlyTokenLimit: event.target.value })}
              placeholder="2000000"
            />
          </Field>
          <Field label="Budget / mois (USD)" htmlFor="key-budget" hint="Vide = illimité">
            <Input
              id="key-budget"
              type="number"
              min={0}
              step="0.01"
              value={form.monthlyBudgetUsd}
              onChange={(event) => setForm({ ...form, monthlyBudgetUsd: event.target.value })}
              placeholder="5.00"
            />
          </Field>
        </div>

        <Field label="Expiration" htmlFor="key-expiry" hint="Vide = pas d'expiration">
          <Input
            id="key-expiry"
            type="date"
            value={form.expiresAt}
            onChange={(event) => setForm({ ...form, expiresAt: event.target.value })}
          />
        </Field>

        <div className="space-y-2">
          <p className="text-sm font-medium text-muted">Modèles accessibles</p>
          <label className="flex items-center gap-2 text-sm text-ink">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-line bg-surface-2"
              checked={form.allModels}
              onChange={(event) => setForm({ ...form, allModels: event.target.checked })}
            />
            Tous les modèles activés
          </label>

          {!form.allModels && (
            <div className="flex flex-wrap gap-2 pt-1">
              {models.map((alias) => (
                <button
                  key={alias}
                  type="button"
                  onClick={() => toggleModel(alias)}
                  className={cn(
                    'rounded-full px-3 py-1 text-xs font-medium transition',
                    form.allowedModels.includes(alias)
                      ? 'bg-primary/15 text-primary'
                      : 'bg-white/5 text-muted hover:bg-white/10',
                  )}
                >
                  {alias}
                </button>
              ))}
            </div>
          )}
          {!form.allModels && form.allowedModels.length === 0 && (
            <p className="text-xs text-warning">
              Aucun modèle sélectionné : cette clé ne pourra appeler aucun modèle.
            </p>
          )}
        </div>

        <Button type="submit" loading={mutation.isPending}>
          <Plus className="h-4 w-4" aria-hidden />
          Créer la clé
        </Button>
      </form>
    </Card>
  );
}

/** Inline editor for the limits and permissions of an existing key. */
function EditKeyPanel({
  apiKey,
  models,
  onDone,
  onError,
}: {
  apiKey: ApiKeyDto;
  models: string[];
  onDone: () => void;
  onError: (message: string | null) => void;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<FormState>({
    name: apiKey.name,
    monthlyRequestLimit: apiKey.monthlyRequestLimit?.toString() ?? '',
    monthlyTokenLimit: apiKey.monthlyTokenLimit?.toString() ?? '',
    monthlyBudgetUsd: apiKey.monthlyBudgetUsd?.toString() ?? '',
    expiresAt: apiKey.expiresAt ? apiKey.expiresAt.slice(0, 10) : '',
    allModels: !apiKey.allowedModels || apiKey.allowedModels.length === 0,
    allowedModels: apiKey.allowedModels ?? [],
  });

  const mutation = useMutation({
    mutationFn: (input: Parameters<typeof patchApiKey>[1]) => patchApiKey(apiKey.id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['gateway', 'api-keys'] });
      onDone();
    },
    onError: (error) => onError(extractApiError(error).message),
  });

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    onError(null);

    mutation.mutate({
      name: form.name.trim(),
      monthlyRequestLimit: form.monthlyRequestLimit ? Number(form.monthlyRequestLimit) : null,
      monthlyTokenLimit: form.monthlyTokenLimit ? Number(form.monthlyTokenLimit) : null,
      monthlyBudgetUsd: form.monthlyBudgetUsd ? Number(form.monthlyBudgetUsd) : null,
      expiresAt: form.expiresAt ? new Date(`${form.expiresAt}T00:00:00`).toISOString() : null,
      allowedModels: form.allModels ? null : form.allowedModels,
    });
  };

  return (
    <div className="border-t border-line bg-surface-3/30 p-5">
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Nom" htmlFor={`edit-name-${apiKey.id}`}>
            <Input
              id={`edit-name-${apiKey.id}`}
              value={form.name}
              onChange={(event) => setForm({ ...form, name: event.target.value })}
              required
            />
          </Field>
          <Field label="Requêtes / mois" htmlFor={`edit-requests-${apiKey.id}`}>
            <Input
              id={`edit-requests-${apiKey.id}`}
              type="number"
              min={1}
              value={form.monthlyRequestLimit}
              onChange={(event) => setForm({ ...form, monthlyRequestLimit: event.target.value })}
            />
          </Field>
          <Field label="Tokens / mois" htmlFor={`edit-tokens-${apiKey.id}`}>
            <Input
              id={`edit-tokens-${apiKey.id}`}
              type="number"
              min={1}
              value={form.monthlyTokenLimit}
              onChange={(event) => setForm({ ...form, monthlyTokenLimit: event.target.value })}
            />
          </Field>
          <Field label="Budget / mois (USD)" htmlFor={`edit-budget-${apiKey.id}`}>
            <Input
              id={`edit-budget-${apiKey.id}`}
              type="number"
              min={0}
              step="0.01"
              value={form.monthlyBudgetUsd}
              onChange={(event) => setForm({ ...form, monthlyBudgetUsd: event.target.value })}
            />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Expiration" htmlFor={`edit-expiry-${apiKey.id}`} hint="Vide = pas d'expiration">
            <Input
              id={`edit-expiry-${apiKey.id}`}
              type="date"
              value={form.expiresAt}
              onChange={(event) => setForm({ ...form, expiresAt: event.target.value })}
            />
          </Field>
          <div className="space-y-2">
            <p className="text-sm font-medium text-muted">Modèles accessibles</p>
            <label className="flex items-center gap-2 text-sm text-ink">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-line bg-surface-2"
                checked={form.allModels}
                onChange={(event) => setForm({ ...form, allModels: event.target.checked })}
              />
              Tous les modèles activés
            </label>
            {!form.allModels && (
              <div className="flex flex-wrap gap-2">
                {models.map((alias) => (
                  <button
                    key={alias}
                    type="button"
                    onClick={() =>
                      setForm((current) => ({
                        ...current,
                        allowedModels: current.allowedModels.includes(alias)
                          ? current.allowedModels.filter((item) => item !== alias)
                          : [...current.allowedModels, alias],
                      }))
                    }
                    className={cn(
                      'rounded-full px-3 py-1 text-xs font-medium transition',
                      form.allowedModels.includes(alias)
                        ? 'bg-primary/15 text-primary'
                        : 'bg-white/5 text-muted hover:bg-white/10',
                    )}
                  >
                    {alias}
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>

        <div className="flex gap-2">
          <Button type="submit" size="sm" loading={mutation.isPending}>
            Enregistrer
          </Button>
          <Button type="button" size="sm" variant="secondary" onClick={onDone}>
            Annuler
          </Button>
        </div>
      </form>
    </div>
  );
}

export function ApiKeysPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedApiKeyDto | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);

  const keysQuery = useQuery({
    queryKey: ['gateway', 'api-keys', { page, search }],
    queryFn: () => getApiKeys({ page, itemsPerPage: ITEMS_PER_PAGE, name: search || undefined }),
  });

  const modelsQuery = useQuery({
    queryKey: ['gateway', 'models'],
    queryFn: getGatewayModels,
    staleTime: 5 * 60_000,
  });

  const modelAliases = (modelsQuery.data?.models ?? []).filter((model) => model.enabled).map((model) => model.alias);

  const toggleMutation = useMutation({
    mutationFn: ({ id, enabled }: { id: number; enabled: boolean }) => patchApiKey(id, { enabled }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['gateway', 'api-keys'] });
    },
    onError: (mutationError) => setError(extractApiError(mutationError).message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteApiKey(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['gateway', 'api-keys'] });
      void queryClient.invalidateQueries({ queryKey: ['gateway', 'dashboard'] });
    },
    onError: (mutationError) => setError(extractApiError(mutationError).message),
  });

  const handleDelete = (apiKey: ApiKeyDto) => {
    setError(null);
    if (!window.confirm(`Révoquer définitivement la clé « ${apiKey.name} » ? Les applications qui l'utilisent seront bloquées.`)) {
      return;
    }
    deleteMutation.mutate(apiKey.id);
  };

  const data = keysQuery.data;
  const totalPages = data ? Math.max(1, Math.ceil(data.totalItems / ITEMS_PER_PAGE)) : 1;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-ink">Clés API</h1>
          <p className="mt-1 text-sm text-muted">
            Identifiants utilisés par les applications clientes pour appeler <code className="font-mono">/v1</code>.
          </p>
        </div>
        {data && <Badge tone="primary">{formatNumber(data.totalItems)} clé(s)</Badge>}
      </div>

      {error && <Alert kind="error">{error}</Alert>}
      {created && <OneTimeSecretPanel created={created} onDismiss={() => setCreated(null)} />}

      <CreateKeyForm models={modelAliases} onCreated={setCreated} onError={setError} />

      <Card>
        <CardHeader
          title="Clés existantes"
          aside={
            <form
              onSubmit={(event) => {
                event.preventDefault();
                setPage(1);
                setSearch(searchInput.trim());
              }}
              className="relative"
            >
              <Search
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-faint"
                aria-hidden
              />
              <Input
                type="search"
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder="Rechercher…"
                className="h-8 w-48 py-1 pl-8 text-xs"
              />
            </form>
          }
        />

        {keysQuery.isLoading && <Spinner label="Chargement des clés…" />}
        {keysQuery.isError && <ErrorState message={extractApiError(keysQuery.error).message} />}

        {data && data.member.length === 0 && (
          <p className="px-5 py-6 text-sm text-muted">Aucune clé pour le moment.</p>
        )}

        {data && data.member.length > 0 && (
          <ul className="divide-y divide-line">
            {data.member.map((apiKey) => (
              <li key={apiKey.id}>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-box bg-primary/12 text-primary">
                    <KeyRound className="h-4 w-4" aria-hidden />
                  </span>

                  <div className="min-w-[12rem] flex-1">
                    <div className="flex items-center gap-2">
                      <p className="font-medium text-ink">{apiKey.name}</p>
                      {apiKey.enabled ? (
                        <Badge tone="success">Active</Badge>
                      ) : (
                        <Badge tone="danger">Désactivée</Badge>
                      )}
                      {apiKey.expiresAt && <Badge tone="warning">Expire le {formatDateTime(apiKey.expiresAt)}</Badge>}
                    </div>
                    <p className="mt-1 font-mono text-xs text-faint">{apiKey.keyPrefix}…</p>
                  </div>

                  <div className="hidden text-xs text-muted lg:block">
                    <p>
                      Limites : {formatNumber(apiKey.monthlyRequestLimit) === '—' ? '∞' : formatNumber(apiKey.monthlyRequestLimit)}{' '}
                      req · {formatNumber(apiKey.monthlyTokenLimit) === '—' ? '∞' : formatNumber(apiKey.monthlyTokenLimit)} tok ·{' '}
                      {apiKey.monthlyBudgetUsd === null ? '∞' : formatUsd(apiKey.monthlyBudgetUsd)}
                    </p>
                    <p className="mt-0.5">
                      Modèles :{' '}
                      {!apiKey.allowedModels || apiKey.allowedModels.length === 0
                        ? 'tous'
                        : apiKey.allowedModels.join(', ')}
                    </p>
                    <p className="mt-0.5">Dernier appel : {formatDateTime(apiKey.lastUsedAt)}</p>
                  </div>

                  <div className="flex items-center gap-1.5">
                    <Button
                      variant="ghost"
                      size="icon"
                      title={apiKey.enabled ? 'Désactiver' : 'Activer'}
                      onClick={() => toggleMutation.mutate({ id: apiKey.id, enabled: !apiKey.enabled })}
                    >
                      {apiKey.enabled ? (
                        <PowerOff className="h-4 w-4" aria-hidden />
                      ) : (
                        <Power className="h-4 w-4" aria-hidden />
                      )}
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      title="Modifier les limites"
                      onClick={() => setEditingId(editingId === apiKey.id ? null : apiKey.id)}
                    >
                      <Pencil className="h-4 w-4" aria-hidden />
                    </Button>
                    <Button
                      variant="dangerGhost"
                      size="icon"
                      title="Révoquer"
                      onClick={() => handleDelete(apiKey)}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden />
                    </Button>
                  </div>
                </div>

                {editingId === apiKey.id && (
                  <EditKeyPanel
                    apiKey={apiKey}
                    models={modelAliases}
                    onDone={() => setEditingId(null)}
                    onError={setError}
                  />
                )}
              </li>
            ))}
          </ul>
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

      <Card className="border-line/70">
        <div className="flex items-start gap-3 p-5">
          <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-success" aria-hidden />
          <div className="space-y-1 text-xs text-muted">
            <p>
              Golden Gate ne stocke qu'un condensat SHA-256 de chaque clé. Le secret n'est affiché qu'à la
              création : en cas de perte, révoquez la clé et créez-en une nouvelle.
            </p>
            <p className="flex items-center gap-1.5">
              <TriangleAlert className="h-3.5 w-3.5 text-warning" aria-hidden />
              Une clé désactivée ou expirée reçoit une erreur <code className="font-mono">401</code>.
            </p>
          </div>
        </div>
      </Card>
    </div>
  );
}
