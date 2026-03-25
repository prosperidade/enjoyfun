export const BOOKING_STATUS_META = {
  pending: { label: "Pendente", className: "badge-yellow" },
  confirmed: { label: "Confirmado", className: "badge-green" },
  contracted: { label: "Contratado", className: "badge-green" },
  tentative: { label: "Tentativo", className: "badge-yellow" },
  optioned: { label: "Em opcao", className: "badge-gray" },
  cancelled: { label: "Cancelado", className: "badge-gray" },
};

export const TIMELINE_STATUS_META = {
  ready: { label: "Pronta", className: "badge-green" },
  attention: { label: "Atencao", className: "badge-yellow" },
  critical: { label: "Critica", className: "badge-red" },
  incomplete: { label: "Incompleta", className: "badge-gray" },
};

export const ALERT_SEVERITY_META = {
  green: { label: "Verde", className: "badge-green" },
  yellow: { label: "Amarelo", className: "badge-yellow" },
  orange: {
    label: "Laranja",
    className:
      "inline-flex items-center rounded-full border border-orange-500/40 bg-orange-500/10 px-2.5 py-1 text-xs font-semibold text-orange-300",
  },
  red: { label: "Vermelho", className: "badge-red" },
  gray: { label: "Cinza", className: "badge-gray" },
};

export const ALERT_STATUS_META = {
  open: { label: "Aberto", className: "badge-red" },
  acknowledged: { label: "Reconhecido", className: "badge-yellow" },
  resolved: { label: "Resolvido", className: "badge-green" },
};

export function resolveMeta(map, value, fallback = "badge-gray") {
  const normalized = String(value || "").trim().toLowerCase();
  if (map[normalized]) {
    return map[normalized];
  }

  return {
    label: normalized || "—",
    className: fallback,
  };
}

export function formatCurrency(value) {
  return new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency: "BRL",
  }).format(Number(value) || 0);
}

export function formatNumber(value) {
  return new Intl.NumberFormat("pt-BR").format(Number(value) || 0);
}

export function formatDate(value) {
  if (!value) {
    return "—";
  }

  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short" }).format(date);
}

export function formatDateTime(value) {
  if (!value) {
    return "—";
  }

  const normalized = String(value).includes("T")
    ? String(value)
    : String(value).replace(" ", "T");
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
}

export function formatDateTimeRelativeLabel(value) {
  if (!value) {
    return "Sem horario";
  }

  return formatDateTime(value);
}

export function formatFileSize(bytes) {
  const amount = Number(bytes || 0);
  if (!Number.isFinite(amount) || amount <= 0) {
    return "—";
  }

  if (amount >= 1024 * 1024) {
    return `${(amount / (1024 * 1024)).toFixed(1)} MB`;
  }
  if (amount >= 1024) {
    return `${(amount / 1024).toFixed(1)} KB`;
  }

  return `${amount} B`;
}

export function formatMinutes(value) {
  const minutes = Number(value || 0);
  if (!Number.isFinite(minutes) || minutes <= 0) {
    return "—";
  }

  if (minutes < 60) {
    return `${minutes} min`;
  }

  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  return remainder > 0 ? `${hours}h ${remainder}min` : `${hours}h`;
}
