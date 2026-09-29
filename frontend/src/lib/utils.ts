/** Utility helpers shared across the app. */

/** Joins class names, ignoring falsy values. */
export function cn(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ');
}

/** Formats an ISO date (UTC from the API) to a French locale date. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }
  return new Date(iso).toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
}

/** Decodes the payload of a Lexik JWT (base64url). Returns null when malformed. */
export interface JwtPayload {
  email: string;
  roles: string[];
  exp: number;
}

export function parseJwt(token: string): JwtPayload | null {
  try {
    const payload = JSON.parse(atob(token.split('.')[1])) as Partial<JwtPayload>;
    return {
      email: payload.email ?? '',
      roles: payload.roles ?? [],
      exp: payload.exp ?? 0,
    };
  } catch {
    return null;
  }
}

/** Extracts the first violation message for a given field from an API 422 body. */
export function violationMessage(
  data: { violations?: Array<{ propertyPath: string; message: string }> } | undefined,
  field: string,
): string | undefined {
  return data?.violations?.find((v) => v.propertyPath === field)?.message;
}
/** Formats an ISO date (UTC from the API) to a French locale date and time. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }
  return new Date(iso).toLocaleString('fr-FR', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
}

/** Formats a large integer with French thousands separators. */
export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '—';
  }
  return value.toLocaleString('fr-FR');
}

/**
 * Formats a USD amount. Sub-cent amounts are shown with enough precision to stay
 * meaningful, since a single request can cost a fraction of a cent.
 */
export function formatUsd(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '—';
  }
  if (value === 0) {
    return '$0.00';
  }
  const decimals = Math.abs(value) < 0.01 ? 6 : 2;
  return `$${value.toLocaleString('fr-FR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
}

/** Formats a latency in milliseconds as a short human-readable duration. */
export function formatLatency(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) {
    return '—';
  }
  if (ms < 1000) {
    return `${ms} ms`;
  }
  return `${(ms / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} s`;
}

/** Turns an `YYYY-MM-DD` input value into the ISO instant the API expects. */
export function dayStartIso(day: string): string | undefined {
  return day ? new Date(`${day}T00:00:00`).toISOString() : undefined;
}

/** Turns an `YYYY-MM-DD` input value into the end of that day, as an ISO instant. */
export function dayEndIso(day: string): string | undefined {
  return day ? new Date(`${day}T23:59:59.999`).toISOString() : undefined;
}
