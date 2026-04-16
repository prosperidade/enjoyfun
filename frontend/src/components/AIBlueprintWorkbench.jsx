import { useEffect, useState } from "react";
import toast from "react-hot-toast";
import {
  BookOpen,
  Brain,
  Layers3,
  Map,
  Sparkles,
  Radar,
  FileStack,
  Wand2,
} from "lucide-react";
import api from "../lib/api";
import {
  getAIBlueprint,
  listAIMemories,
  listAIReports,
  queueAIEndOfEventReport,
} from "../api/ai";

const DOMAIN_TARGETS = [
  {
    key: "artists",
    title: "Artists Hub",
    description:
      "Capacidades para consolidar lineup, logistica, arquivos, pendencias contratuais e sugerir defaults nos modais do evento.",
    capabilities: [
      "artists.read_event_snapshot",
      "artists.read_logistics",
      "artists.read_contracting_status",
      "artists.suggest_modal_defaults",
    ],
  },
  {
    key: "finance",
    title: "Finance Hub",
    description:
      "Capacidades para ler budgets, payables, suppliers, risco financeiro e orientar preenchimento e saneamento operacional.",
    capabilities: [
      "finance.read_budget_health",
      "finance.read_payables_risk",
      "finance.read_supplier_history",
      "finance.suggest_form_defaults",
    ],
  },
];

function formatDateTime(value) {
  if (!value) return "n/d";

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "n/d";

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
}

export default function AIBlueprintWorkbench() {
  const [blueprint, setBlueprint] = useState(null);
  const [memories, setMemories] = useState([]);
  const [reports, setReports] = useState([]);
  const [events, setEvents] = useState([]);
  const [selectedEventId, setSelectedEventId] = useState("");
  const [loading, setLoading] = useState(true);
  const [queueing, setQueueing] = useState(false);

  const loadData = async ({ silent = false } = {}) => {
    if (!silent) {
      setLoading(true);
    }

    try {
      const [blueprintData, memoryList, reportList, eventResponse] = await Promise.all([
        getAIBlueprint(),
        listAIMemories({ limit: 6 }),
        listAIReports({ limit: 6 }),
        api.get("/events"),
      ]);
      setBlueprint(blueprintData);
      setMemories(memoryList);
      setReports(reportList);
      setEvents(Array.isArray(eventResponse?.data?.data) ? eventResponse.data.data : []);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao carregar a arquitetura viva de IA.");
    } finally {
      if (!silent) {
        setLoading(false);
      }
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleQueueReport = async () => {
    const eventId = Number(selectedEventId);
    if (eventId <= 0) {
      toast.error("Selecione um evento para enfileirar o relatório final.");
      return;
    }

    setQueueing(true);
    try {
      await queueAIEndOfEventReport(eventId);
      toast.success("Relatório final enfileirado.");
      await loadData({ silent: true });
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao enfileirar relatório final.");
    } finally {
      setQueueing(false);
    }
  };

  if (loading) {
    return <div className="text-slate-500 animate-pulse">Carregando blueprint vivo da IA...</div>;
  }

  const layers = Array.isArray(blueprint?.layers) ? blueprint.layers : [];
  const surfaces = Array.isArray(blueprint?.surface_blueprints)
    ? blueprint.surface_blueprints
    : [];
  const promptCatalog = Array.isArray(blueprint?.prompt_catalog)
    ? blueprint.prompt_catalog
    : [];
  const reportBlueprint = blueprint?.end_of_event_report || { sections: [] };
  const implementedSurfaces = surfaces.filter((item) => item.status === "implemented").length;

  return (
    <section className="space-y-6 fade-in">
      <div className="relative overflow-hidden rounded-[2rem] border border-purple-900/40 bg-[#111827] p-8">
        <div className="absolute inset-y-0 right-0 w-1/2 bg-[radial-gradient(circle_at_center,_rgba(168,85,247,0.18),_transparent_55%)] pointer-events-none" />
        <div className="relative grid gap-6 xl:grid-cols-[1.35fr,0.95fr]">
          <div className="space-y-5">
            <span className="inline-flex items-center gap-2 rounded-full border border-purple-700/50 bg-purple-500/15 px-3 py-1 text-[11px] uppercase tracking-[0.32em] text-purple-400">
              <Sparkles size={13} /> Hub vivo da inteligencia
            </span>
            <div className="space-y-3">
              <h2 className="text-3xl font-black tracking-tight text-slate-100 sm:text-4xl">
                Camadas, memoria viva e automacao final do evento no mesmo cockpit.
              </h2>
              <p className="max-w-3xl text-sm leading-relaxed text-slate-300">
                O hub agora concentra o contrato de contexto por superficie, o catalogo versionado
                de prompts, a memoria persistida dos agentes e o relatorio automatico de fim de
                evento que vira material de aprendizado para o organizer.
              </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-4">
              <MetricChip label="Camadas" value={layers.length} tone="cyan" />
              <MetricChip label="Superficies ativas" value={implementedSurfaces} tone="blue" />
              <MetricChip label="Memorias" value={memories.length} tone="emerald" />
              <MetricChip label="Relatorios" value={reports.length} tone="amber" />
            </div>
          </div>

          <div className="rounded-[1.75rem] border border-white/10 bg-slate-950/55 p-5 backdrop-blur">
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-100">
              <FileStack size={16} className="text-purple-400" /> Relatorio automatico de fim de evento
            </div>
            <p className="mt-2 text-sm text-slate-400">
              Quando o evento muda para <code>finished</code>, a fila do relatorio final e aberta.
              Aqui voce tambem consegue enfileirar manualmente para teste e auditoria do fluxo.
            </p>

            <div className="mt-5 space-y-3">
              <label className="input-label text-purple-200/80">Evento alvo</label>
              <select
                className="input bg-slate-950/55"
                value={selectedEventId}
                onChange={(event) => setSelectedEventId(event.target.value)}
              >
                <option value="">Selecionar evento...</option>
                {events.map((event) => (
                  <option key={event.id} value={event.id}>
                    {event.name}
                  </option>
                ))}
              </select>
              <button
                type="button"
                className="btn-primary w-full flex items-center justify-center gap-2"
                disabled={queueing}
                onClick={handleQueueReport}
              >
                <Wand2 size={16} />
                {queueing ? "Enfileirando..." : "Enfileirar relatorio final"}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div className="grid gap-5 xl:grid-cols-[1.1fr,0.9fr]">
        <div className="card space-y-5 p-8">
          <div className="flex items-center gap-2">
            <Layers3 size={18} className="text-purple-400" />
            <h3 className="section-title mb-0">Arquitetura viva</h3>
          </div>
          <div className="grid gap-4 md:grid-cols-3">
            {layers.map((layer) => (
              <div
                key={layer.key}
                className="rounded-2xl border border-slate-800/40 bg-[#111827] p-4"
              >
                <p className="text-[11px] uppercase tracking-[0.28em] text-purple-400 mb-2">
                  {layer.status}
                </p>
                <h4 className="text-slate-100 font-bold">{layer.label}</h4>
                <p className="mt-2 text-sm text-slate-400">{layer.description}</p>
              </div>
            ))}
          </div>

          <div className="rounded-2xl border border-slate-800/40 bg-[#111827] p-5">
            <div className="flex items-center gap-2 mb-3">
              <Map size={16} className="text-purple-400" />
              <p className="font-semibold text-slate-100">Superficies e builders</p>
            </div>
            <div className="grid gap-3 md:grid-cols-2">
              {surfaces.map((surface) => (
                <div key={surface.surface} className="rounded-xl border border-slate-800/40 bg-slate-900/60 p-4">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="font-semibold text-slate-100">{surface.label}</p>
                      <p className="text-xs text-slate-500 mt-1">{surface.agent_key}</p>
                    </div>
                    <span className={`badge ${surface.status === "implemented" ? "badge-green" : "badge-gray"}`}>
                      {surface.status}
                    </span>
                  </div>
                  <p className="mt-3 text-xs text-slate-400">
                    Fontes: {(surface.context_sources || []).join(" • ")}
                  </p>
                  <p className="mt-2 text-xs text-slate-500">
                    Saidas: {(surface.output_focus || []).join(" • ")}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="card space-y-5 p-8">
          <div className="flex items-center gap-2">
            <Radar size={18} className="text-purple-400" />
            <h3 className="section-title mb-0">Roadmap de capacidades</h3>
          </div>
          <div className="space-y-4">
            {DOMAIN_TARGETS.map((target) => (
              <div key={target.key} className="rounded-2xl border border-slate-800/40 bg-[#111827] p-5">
                <p className="text-slate-100 font-bold">{target.title}</p>
                <p className="mt-2 text-sm text-slate-400">{target.description}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {target.capabilities.map((capability) => (
                    <span
                      key={capability}
                      className="rounded-full border border-purple-900/50 bg-purple-500/15 px-2 py-1 text-[11px] text-purple-300"
                    >
                      {capability}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>

          <div className="rounded-2xl border border-slate-800/40 bg-[#111827] p-5">
            <div className="flex items-center gap-2 mb-3">
              <BookOpen size={16} className="text-purple-400" />
              <p className="font-semibold text-slate-100">Catalogo de prompts</p>
            </div>
            <div className="space-y-3">
              {promptCatalog.map((item) => (
                <div key={item.agent_key} className="rounded-xl border border-slate-800/40 bg-slate-900/60 p-4">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-semibold text-slate-100">{item.label}</p>
                    <span className="text-[11px] uppercase tracking-[0.24em] text-slate-500">
                      {item.agent_key}
                    </span>
                  </div>
                  <p className="mt-2 text-sm text-slate-400">{item.report_goal}</p>
                  <p className="mt-2 text-xs text-slate-500">
                    Superficies: {(item.surfaces || []).join(" • ")}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      <div className="grid gap-5 xl:grid-cols-[1.15fr,0.85fr]">
        <div className="card space-y-5 p-8">
          <div className="flex items-center gap-2">
            <Brain size={18} className="text-purple-400" />
            <h3 className="section-title mb-0">Memoria viva recente</h3>
          </div>
          {memories.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-800/40 bg-slate-950/60 p-6 text-sm text-slate-400">
              Nenhuma memoria persistida ainda. As proximas execucoes do orquestrador passam a
              alimentar essa trilha.
            </div>
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {memories.map((memory) => (
                <div key={memory.id} className="rounded-2xl border border-slate-800/40 bg-[#111827] p-5">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-semibold text-slate-100">{memory.title || "Memoria de execucao"}</p>
                    <span className="badge badge-gray">P{memory.importance}</span>
                  </div>
                  <p className="mt-2 text-sm text-slate-300 line-clamp-4">{memory.summary}</p>
                  <div className="mt-4 flex flex-wrap gap-2 text-[11px] text-slate-500">
                    <span className="rounded-full border border-slate-800/40 px-2 py-1">
                      {memory.agent_key || "sem agente"}
                    </span>
                    <span className="rounded-full border border-slate-800/40 px-2 py-1">
                      {memory.surface || "sem superficie"}
                    </span>
                    <span className="rounded-full border border-slate-800/40 px-2 py-1">
                      {formatDateTime(memory.created_at)}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="card space-y-5 p-8">
          <div className="flex items-center gap-2">
            <FileStack size={18} className="text-purple-400" />
            <h3 className="section-title mb-0">Fila de relatorios finais</h3>
          </div>

          <div className="rounded-2xl border border-slate-800/40 bg-[#111827] p-5">
            <p className="font-semibold text-slate-100">{reportBlueprint.title_template || "Raio X final"}</p>
            <p className="mt-2 text-sm text-slate-400">{reportBlueprint.objective}</p>
            <div className="mt-4 space-y-3">
              {(reportBlueprint.sections || []).map((section) => (
                <div key={section.section_key} className="rounded-xl border border-slate-800/40 bg-slate-900/60 p-4">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-semibold text-slate-100">{section.section_title}</p>
                    <span className="text-[11px] uppercase tracking-[0.24em] text-slate-500">
                      {section.agent_key}
                    </span>
                  </div>
                  <p className="mt-2 text-xs text-slate-500">
                    Campos: {(section.required_fields || []).join(" • ")}
                  </p>
                </div>
              ))}
            </div>
          </div>

          <div className="space-y-3">
            {reports.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-slate-800/40 bg-slate-950/60 p-6 text-sm text-slate-400">
                Nenhum relatorio final enfileirado ainda.
              </div>
            ) : (
              reports.map((report) => (
                <div key={report.id} className="rounded-2xl border border-slate-800/40 bg-[#111827] p-5">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-semibold text-slate-100">{report.title || "Relatorio final"}</p>
                    <span className={`badge ${report.report_status === "ready" ? "badge-green" : "badge-yellow"}`}>
                      {report.report_status}
                    </span>
                  </div>
                  <p className="mt-2 text-sm text-slate-400">
                    Evento {report.event_id} • {report.automation_source}
                  </p>
                  <p className="mt-2 text-xs text-slate-500">
                    Gerado em {formatDateTime(report.generated_at)}
                  </p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {(report.sections || []).map((section) => (
                      <span
                        key={section.id}
                        className="rounded-full border border-slate-800/40 px-2 py-1 text-[11px] text-slate-400"
                      >
                        {section.agent_key}: {section.section_status}
                      </span>
                    ))}
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </section>
  );
}

function MetricChip({ label, value, tone }) {
  const tones = {
    cyan: "border-cyan-900/60 bg-cyan-950/40 text-purple-300",
    blue: "border-blue-900/60 bg-blue-950/40 text-blue-200",
    emerald: "border-emerald-900/60 bg-emerald-950/40 text-emerald-200",
    amber: "border-amber-900/60 bg-amber-950/40 text-amber-200",
  };

  return (
    <div className={`rounded-2xl border px-4 py-3 ${tones[tone] || tones.cyan}`}>
      <p className="text-[11px] uppercase tracking-[0.24em] opacity-80">{label}</p>
      <p className="mt-1 text-2xl font-black">{value}</p>
    </div>
  );
}
