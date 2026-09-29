import { useQuery } from '@tanstack/react-query';
import {
  Activity,
  AlertTriangle,
  CheckCircle2,
  Coins,
  Gauge,
  RefreshCw,
  TriangleAlert,
} from 'lucide-react';
import { getGatewayDashboard } from '../../api/gateway';
import { extractApiError } from '../../api/client';
import { Badge, Button, Card, CardHeader, ErrorState, Spinner } from '../../components/ui';
import { formatDateTime, formatLatency, formatNumber, formatUsd } from '../../lib/utils';

/** One headline number of the dashboard. */
function StatCard({
  label,
  value,
  hint,
  icon: Icon,
  tone = 'primary',
}: {
  label: string;
  value: string;
  hint?: string;
  icon: typeof Activity;
  tone?: 'primary' | 'accent' | 'success' | 'danger';
}) {
  const tones = {
    primary: 'text-primary bg-primary/12',
    accent: 'text-accent bg-accent/12',
    success: 'text-success bg-success/12',
    danger: 'text-danger bg-danger/12',
  } as const;

  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-widest text-faint">{label}</p>
          <p className="mt-2 text-2xl font-bold text-ink">{value}</p>
          {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
        </div>
        <span className={`flex h-9 w-9 items-center justify-center rounded-box ${tones[tone]}`}>
          <Icon className="h-4 w-4" aria-hidden />
        </span>
      </div>
    </Card>
  );
}

export function DashboardPage() {
  const dashboardQuery = useQuery({
    queryKey: ['gateway', 'dashboard'],
    queryFn: getGatewayDashboard,
    refetchInterval: 30_000,
  });

  if (dashboardQuery.isLoading) {
    return <Spinner label="Chargement du tableau de bord…" />;
  }

  if (dashboardQuery.isError) {
    return <ErrorState message={extractApiError(dashboardQuery.error).message} />;
  }

  const data = dashboardQuery.data;
  if (!data) {
    return <ErrorState message="Aucune donnée disponible." />;
  }

  const attempts = data.successCount + data.errorCount;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-ink">Tableau de bord</h1>
          <p className="mt-1 text-sm text-muted">
            Consommation du gateway depuis le {formatDateTime(data.periodStart)}.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Badge tone="primary">Mois en cours</Badge>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => void dashboardQuery.refetch()}
            loading={dashboardQuery.isFetching}
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden />
            Rafraîchir
          </Button>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Requêtes" value={formatNumber(data.requests)} icon={Activity} hint="Appels servis ce mois" />
        <StatCard label="Tokens" value={formatNumber(data.tokens)} icon={Gauge} tone="accent" hint="Entrée + sortie" />
        <StatCard
          label="Dépense estimée"
          value={formatUsd(data.estimatedCostUsd)}
          icon={Coins}
          tone="primary"
          hint="Coût estimé d'après les tarifs configurés"
        />
        <StatCard
          label="Succès / erreurs"
          value={`${formatNumber(data.successCount)} / ${formatNumber(data.errorCount)}`}
          icon={data.errorCount > 0 ? TriangleAlert : CheckCircle2}
          tone={data.errorCount > 0 ? 'danger' : 'success'}
          hint={attempts > 0 ? `${Math.round((data.successCount / attempts) * 100)} % de succès` : 'Aucun appel'}
        />
      </div>

      <Card>
        <CardHeader title="Consommation par modèle" />
        {data.byModel.length === 0 ? (
          <p className="px-5 py-6 text-sm text-muted">Aucun appel enregistré ce mois-ci.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-line text-xs uppercase tracking-widest text-faint">
                  <th className="px-5 py-3 font-semibold">Modèle</th>
                  <th className="px-5 py-3 font-semibold">Fournisseur</th>
                  <th className="px-5 py-3 text-right font-semibold">Requêtes</th>
                  <th className="px-5 py-3 text-right font-semibold">Tokens</th>
                  <th className="px-5 py-3 text-right font-semibold">Coût estimé</th>
                </tr>
              </thead>
              <tbody>
                {data.byModel.map((row) => (
                  <tr key={`${row.model}-${row.provider}`} className="border-b border-line/60 last:border-0">
                    <td className="px-5 py-3 font-medium text-ink">{row.model}</td>
                    <td className="px-5 py-3 text-muted">{row.provider}</td>
                    <td className="px-5 py-3 text-right text-ink">{formatNumber(row.requests)}</td>
                    <td className="px-5 py-3 text-right text-ink">{formatNumber(row.tokens)}</td>
                    <td className="px-5 py-3 text-right text-ink">{formatUsd(row.estimatedCostUsd)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card>
        <CardHeader
          title="Requêtes récentes"
          aside={<Badge tone="neutral">{formatNumber(data.recentRequests.length)} dernières</Badge>}
        />
        {data.recentRequests.length === 0 ? (
          <p className="px-5 py-6 text-sm text-muted">Aucune requête pour le moment.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-line text-xs uppercase tracking-widest text-faint">
                  <th className="px-5 py-3 font-semibold">Date</th>
                  <th className="px-5 py-3 font-semibold">Clé</th>
                  <th className="px-5 py-3 font-semibold">Modèle</th>
                  <th className="px-5 py-3 font-semibold">Statut</th>
                  <th className="px-5 py-3 text-right font-semibold">Tokens</th>
                  <th className="px-5 py-3 text-right font-semibold">Coût</th>
                  <th className="px-5 py-3 text-right font-semibold">Latence</th>
                </tr>
              </thead>
              <tbody>
                {data.recentRequests.map((request) => (
                  <tr key={request.id} className="border-b border-line/60 last:border-0">
                    <td className="whitespace-nowrap px-5 py-3 text-muted">{formatDateTime(request.createdAt)}</td>
                    <td className="px-5 py-3 font-mono text-xs text-muted">{request.apiKeyPrefix}</td>
                    <td className="px-5 py-3 font-medium text-ink">{request.model}</td>
                    <td className="px-5 py-3">
                      {request.status === 'success' ? (
                        <Badge tone="success">
                          <CheckCircle2 className="h-3 w-3" aria-hidden />
                          Succès
                        </Badge>
                      ) : (
                        <Badge tone="danger">
                          <AlertTriangle className="h-3 w-3" aria-hidden />
                          {request.errorCategory ?? 'Erreur'}
                        </Badge>
                      )}
                    </td>
                    <td className="px-5 py-3 text-right text-ink">{formatNumber(request.totalTokens)}</td>
                    <td className="px-5 py-3 text-right text-ink">{formatUsd(request.estimatedCostUsd)}</td>
                    <td className="px-5 py-3 text-right text-muted">{formatLatency(request.latencyMs)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
