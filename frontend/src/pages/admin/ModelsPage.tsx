import { useQuery } from '@tanstack/react-query';
import { Boxes, Info } from 'lucide-react';
import { getGatewayModels } from '../../api/gateway';
import { extractApiError } from '../../api/client';
import { Badge, Card, CardHeader, ErrorState, Spinner } from '../../components/ui';
import { formatNumber, formatUsd } from '../../lib/utils';

/**
 * Read-only view of the model registry.
 *
 * Provider configuration stays server-side on purpose: addresses and credentials are
 * deliberately not editable from the browser.
 */
export function ModelsPage() {
  const modelsQuery = useQuery({
    queryKey: ['gateway', 'models'],
    queryFn: getGatewayModels,
    staleTime: 5 * 60_000,
  });

  if (modelsQuery.isLoading) {
    return <Spinner label="Chargement des modèles…" />;
  }

  if (modelsQuery.isError) {
    return <ErrorState message={extractApiError(modelsQuery.error).message} />;
  }

  const models = modelsQuery.data?.models ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-ink">Modèles exposés</h1>
          <p className="mt-1 text-sm text-muted">
            Alias publics résolus vers leur fournisseur. Configuration côté serveur
            (<code className="font-mono">config/packages/golden_gate.yaml</code>).
          </p>
        </div>
        <Badge tone="primary">{models.length} modèle(s)</Badge>
      </div>

      <Card>
        <CardHeader title="Registre des modèles" />
        {models.length === 0 ? (
          <p className="px-5 py-6 text-sm text-muted">Aucun modèle configuré.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-line text-xs uppercase tracking-widest text-faint">
                  <th className="px-5 py-3 font-semibold">Alias public</th>
                  <th className="px-5 py-3 font-semibold">Fournisseur</th>
                  <th className="px-5 py-3 font-semibold">Modèle amont</th>
                  <th className="px-5 py-3 font-semibold">État</th>
                  <th className="px-5 py-3 text-right font-semibold">Entrée / 1M</th>
                  <th className="px-5 py-3 text-right font-semibold">Sortie / 1M</th>
                  <th className="px-5 py-3 text-right font-semibold">Contexte</th>
                </tr>
              </thead>
              <tbody>
                {models.map((model) => (
                  <tr key={model.alias} className="border-b border-line/60 last:border-0">
                    <td className="px-5 py-3">
                      <p className="font-medium text-ink">{model.alias}</p>
                      {model.description && <p className="mt-0.5 max-w-md text-xs text-faint">{model.description}</p>}
                    </td>
                    <td className="px-5 py-3 text-muted">{model.provider}</td>
                    <td className="px-5 py-3 font-mono text-xs text-muted">{model.upstreamModel}</td>
                    <td className="px-5 py-3">
                      {model.enabled ? <Badge tone="success">Activé</Badge> : <Badge tone="neutral">Désactivé</Badge>}
                    </td>
                    <td className="px-5 py-3 text-right text-ink">{formatUsd(model.inputPricePerMillionUsd)}</td>
                    <td className="px-5 py-3 text-right text-ink">{formatUsd(model.outputPricePerMillionUsd)}</td>
                    <td className="px-5 py-3 text-right text-muted">{formatNumber(model.contextWindow)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card className="border-line/70">
        <div className="flex items-start gap-3 p-5 text-xs text-muted">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden />
          <div className="space-y-1">
            <p>
              Ces modèles sont en lecture seule : les identifiants du fournisseur et les secrets d'API restent
              dans l'environnement du serveur et ne sont jamais exposés au navigateur.
            </p>
            <p className="flex items-center gap-1.5">
              <Boxes className="h-3.5 w-3.5 text-faint" aria-hidden />
              Le même alias fonctionne quel que soit le fournisseur : c'est tout l'intérêt du gateway.
            </p>
          </div>
        </div>
      </Card>
    </div>
  );
}
