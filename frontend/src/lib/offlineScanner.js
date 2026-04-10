const SCANNER_TOKEN_KEYS = ["dynamic_token", "qr_token", "token", "code"];

function trimScannerValue(value) {
  return String(value ?? "").trim().replace(/^["']|["']$/g, "");
}

export function extractScannerPayloadValue(rawValue = "") {
  let value = trimScannerValue(rawValue);
  if (!value) {
    return "";
  }

  if (value.startsWith("{") && value.endsWith("}")) {
    try {
      const decoded = JSON.parse(value);
      for (const key of SCANNER_TOKEN_KEYS) {
        const candidate = trimScannerValue(decoded?.[key]);
        if (candidate) {
          value = candidate;
          break;
        }
      }
    } catch {
      // Mantém o valor original.
    }
  }

  if (value.includes("?")) {
    try {
      const query = value.startsWith("http://") || value.startsWith("https://") || value.startsWith("/")
        ? new URL(value, window.location.origin).search
        : `?${value.split("?").slice(1).join("?")}`;
      const params = new URLSearchParams(query);

      for (const key of SCANNER_TOKEN_KEYS) {
        const candidate = trimScannerValue(params.get(key));
        if (candidate) {
          return candidate;
        }
      }
    } catch {
      // Mantém o valor original.
    }
  }

  return value;
}

export function buildOfflineScannerLookupCandidates(rawValue = "") {
  const normalized = extractScannerPayloadValue(rawValue);
  if (!normalized) {
    return { normalized: "", tokenCandidates: [], refCandidates: [] };
  }

  const tokenCandidates = [];
  const pushTokenCandidate = (candidate) => {
    const value = trimScannerValue(candidate);
    if (value && !tokenCandidates.includes(value)) {
      tokenCandidates.push(value);
    }
  };

  pushTokenCandidate(normalized);

  const parts = normalized.split(".");
  if (parts.length === 2 && /^\d+$/.test(parts[1] || "")) {
    pushTokenCandidate(parts[0]);
  }

  const refCandidates = tokenCandidates
    .map((candidate) => trimScannerValue(candidate).toUpperCase())
    .filter((candidate, index, list) => candidate && list.indexOf(candidate) === index);

  return {
    normalized,
    tokenCandidates,
    refCandidates,
  };
}

export function buildScannerCacheRecord(item = {}, eventId, metadata = {}) {
  const normalizedToken = extractScannerPayloadValue(item?.token);
  const fallbackKey = trimScannerValue(item?.ref).toUpperCase() || `${trimScannerValue(item?.type) || "scanner"}:${trimScannerValue(item?.id) || Date.now()}`;
  return {
    ...item,
    token: normalizedToken || fallbackKey,
    event_id: Number(eventId),
    snapshot_id: trimScannerValue(metadata?.snapshotId),
    sync_scope: trimScannerValue(metadata?.scope),
    used_offline: 0,
    token_lookup: normalizedToken,
    ref_lookup: trimScannerValue(item?.ref).toUpperCase(),
  };
}
