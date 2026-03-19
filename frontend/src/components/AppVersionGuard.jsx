import { useEffect, useEffectEvent, useMemo, useRef, useState } from "react";
import toast from "react-hot-toast";
import { useLocation } from "react-router-dom";

const VERSION_CHECK_INTERVAL_MS = 60_000;
const OPERATIONAL_PATH_PREFIXES = [
  "/meals-control",
  "/scanner",
  "/bar",
  "/food",
  "/shop",
  "/parking",
];
const CURRENT_BUILD_ID = String(import.meta.env.VITE_APP_BUILD_ID || "").trim();
const CURRENT_BUILD_AT = String(import.meta.env.VITE_APP_BUILD_AT || "").trim();
const CURRENT_APP_VERSION = String(import.meta.env.VITE_APP_VERSION || "").trim();

function isOperationalPath(pathname = "") {
  return OPERATIONAL_PATH_PREFIXES.some((prefix) =>
    pathname === prefix || pathname.startsWith(`${prefix}/`)
  );
}

function formatBuildMoment(value = "") {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export default function AppVersionGuard() {
  const location = useLocation();
  const [staleBuild, setStaleBuild] = useState(null);
  const [dismissedBuildId, setDismissedBuildId] = useState("");
  const reloadTimerRef = useRef(null);
  const detectedBuildIdRef = useRef("");
  const operationalRoute = useMemo(
    () => isOperationalPath(location.pathname),
    [location.pathname]
  );
  const versionManifestUrl = `${import.meta.env.BASE_URL}app-version.json`;

  const clearReloadTimer = useEffectEvent(() => {
    if (reloadTimerRef.current) {
      window.clearTimeout(reloadTimerRef.current);
      reloadTimerRef.current = null;
    }
  });

  const reloadApplication = useEffectEvent(() => {
    clearReloadTimer();
    toast.loading("Nova versao detectada. Recarregando a estacao...", {
      id: "app-version-guard",
      duration: Infinity,
    });
    window.setTimeout(() => {
      window.location.reload();
    }, 900);
  });

  const checkVersion = useEffectEvent(async () => {
    if (import.meta.env.DEV || !CURRENT_BUILD_ID) {
      return;
    }

    try {
      const response = await fetch(`${versionManifestUrl}?ts=${Date.now()}`, {
        cache: "no-store",
        headers: {
          "cache-control": "no-cache",
        },
      });
      if (!response.ok) {
        return;
      }

      const payload = await response.json();
      const nextBuildId = String(payload?.buildId || "").trim();
      if (!nextBuildId || nextBuildId === CURRENT_BUILD_ID || nextBuildId === detectedBuildIdRef.current) {
        return;
      }

      detectedBuildIdRef.current = nextBuildId;
      setStaleBuild({
        buildId: nextBuildId,
        builtAt: String(payload?.builtAt || "").trim(),
        version: String(payload?.version || "").trim(),
      });
    } catch {
      // Falha de rede ou manifesto indisponivel nao deve interromper a operacao.
    }
  });

  useEffect(() => {
    if (import.meta.env.DEV || !CURRENT_BUILD_ID) {
      return undefined;
    }

    checkVersion();

    const handleAttention = () => {
      if (document.visibilityState !== "hidden") {
        checkVersion();
      }
    };

    const intervalId = window.setInterval(() => {
      if (document.visibilityState !== "hidden") {
        checkVersion();
      }
    }, VERSION_CHECK_INTERVAL_MS);

    window.addEventListener("focus", handleAttention);
    document.addEventListener("visibilitychange", handleAttention);

    return () => {
      window.clearInterval(intervalId);
      window.removeEventListener("focus", handleAttention);
      document.removeEventListener("visibilitychange", handleAttention);
      clearReloadTimer();
    };
  }, []);

  useEffect(() => {
    if (!staleBuild) {
      toast.dismiss("app-version-guard");
      clearReloadTimer();
      return;
    }

    clearReloadTimer();
    toast.dismiss("app-version-guard");
  }, [operationalRoute, staleBuild]);

  if (
    import.meta.env.DEV ||
    !CURRENT_BUILD_ID ||
    !staleBuild ||
    (dismissedBuildId === staleBuild.buildId && !operationalRoute)
  ) {
    return null;
  }

  const latestBuildLabel = formatBuildMoment(staleBuild.builtAt);
  const currentBuildLabel = formatBuildMoment(CURRENT_BUILD_AT);

  return (
    <div className="fixed inset-x-0 top-0 z-[120] px-3 pt-3">
      <div className="mx-auto max-w-4xl rounded-2xl border border-amber-500/40 bg-gray-950/95 px-4 py-3 shadow-2xl backdrop-blur">
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div className="space-y-1">
            <p className="text-sm font-semibold text-amber-300">
              Nova versao do sistema detectada nesta estacao
            </p>
            <p className="text-xs text-gray-300">
              Build atual: {CURRENT_APP_VERSION || "local"}{currentBuildLabel ? ` (${currentBuildLabel})` : ""}.
              Build disponivel: {staleBuild.version || "nova versao"}{latestBuildLabel ? ` (${latestBuildLabel})` : ""}.
            </p>
            <p className="text-xs text-gray-400">
              {operationalRoute
                ? "Rota operacional critica aberta. A atualizacao ficou manual para evitar reinicios automaticos da estacao."
                : "Atualize esta aba antes de continuar usando telas com impacto operacional."}
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              className="btn-primary"
              onClick={reloadApplication}
            >
              Atualizar agora
            </button>
            {!operationalRoute ? (
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setDismissedBuildId(staleBuild.buildId)}
              >
                Depois
              </button>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  );
}
