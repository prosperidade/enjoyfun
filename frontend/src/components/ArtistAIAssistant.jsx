import { useState } from "react";
import toast from "react-hot-toast";
import { Bot, MicVocal, Send, AlertTriangle, DollarSign, Users, Clock } from "lucide-react";
import api from "../lib/api";

export default function ArtistAIAssistant({
  eventId,
  artistsTotal = 0,
  confirmedCount = 0,
  pendingCount = 0,
  totalCost = 0,
  openAlertsCount = 0,
  criticalAlertsCount = 0,
  focusArtistName = null,
  focusArtistId = null,
}) {
  const [question, setQuestion] = useState("");
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(false);

  const focusLabel = focusArtistName
    ? focusArtistName
    : "Visao geral de todos os artistas";

  const handleAsk = async () => {
    const trimmedQuestion = question.trim();
    const normalizedEventId = Number(eventId);

    if (!trimmedQuestion) return;
    if (normalizedEventId <= 0) {
      toast.error("Selecione um evento para consultar o agente de artistas.");
      return;
    }

    setQuestion("");
    setMessages((current) => [...current, { role: "user", content: trimmedQuestion }]);
    setLoading(true);

    try {
      const response = await api.post("/ai/insight", {
        question: trimmedQuestion,
        context: {
          event_id: normalizedEventId,
          surface: "artists",
          agent_key: "artists",
          event_artist_id: focusArtistId ? Number(focusArtistId) : undefined,
          artists_total_hint: Number(artistsTotal || 0),
          confirmed_hint: Number(confirmedCount || 0),
          pending_hint: Number(pendingCount || 0),
          total_cost_hint: Number(totalCost || 0),
          open_alerts_hint: Number(openAlertsCount || 0),
          critical_alerts_hint: Number(criticalAlertsCount || 0),
        },
      });

      setMessages((current) => [
        ...current,
        {
          role: "ai",
          content: response.data?.data?.insight || "Sem resposta do agente de artistas.",
        },
      ]);
    } catch (error) {
      const message = error.response?.data?.message || "Erro ao consultar o agente de artistas.";
      setMessages((current) => [...current, { role: "ai", content: `Erro: ${message}` }]);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card border-emerald-900/40 bg-[linear-gradient(135deg,_rgba(6,78,59,0.20),_rgba(15,23,42,0.94))] p-6">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div>
          <div className="flex items-center gap-2 text-emerald-200">
            <MicVocal size={18} />
            <p className="font-semibold">Agente de Artistas</p>
          </div>
          <p className="mt-2 max-w-3xl text-sm text-gray-300">
            Analisa logistica, timeline, alertas, custos e equipe de cada artista do evento.
            Identifica gargalos criticos e propoe acoes de fechamento.
          </p>
        </div>

        <div className="rounded-2xl border border-emerald-900/40 bg-slate-950/55 px-4 py-3 text-xs text-emerald-100">
          <p className="uppercase tracking-[0.24em] text-emerald-300">Foco atual</p>
          <p className="mt-2">{focusLabel}</p>
        </div>
      </div>

      <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <div className="flex items-center gap-2 text-gray-400">
            <Users size={15} />
            <p className="text-[10px] uppercase tracking-wider">Artistas</p>
          </div>
          <p className="mt-2 text-xl font-black text-white">
            {Number(confirmedCount || 0)}
            <span className="ml-1 text-sm font-normal text-gray-500">
              confirmados
            </span>
          </p>
          <p className="mt-1 text-xs text-gray-500">
            {Number(pendingCount || 0)} pendentes de {Number(artistsTotal || 0)} total
          </p>
        </div>

        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <div className="flex items-center gap-2 text-gray-400">
            <AlertTriangle size={15} />
            <p className="text-[10px] uppercase tracking-wider">Alertas</p>
          </div>
          <p className={`mt-2 text-xl font-black ${Number(criticalAlertsCount || 0) > 0 ? "text-red-400" : Number(openAlertsCount || 0) > 0 ? "text-amber-300" : "text-white"}`}>
            {Number(openAlertsCount || 0)}
            <span className="ml-1 text-sm font-normal text-gray-500">abertos</span>
          </p>
          <p className="mt-1 text-xs text-gray-500">
            {Number(criticalAlertsCount || 0)} criticos (red)
          </p>
        </div>

        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <div className="flex items-center gap-2 text-gray-400">
            <DollarSign size={15} />
            <p className="text-[10px] uppercase tracking-wider">Custo total</p>
          </div>
          <p className="mt-2 text-xl font-black text-white">
            R$ {Number(totalCost || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}
          </p>
          <p className="mt-1 text-xs text-gray-500">Cache + logistica</p>
        </div>

        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <div className="flex items-center gap-2 text-gray-400">
            <Clock size={15} />
            <p className="text-[10px] uppercase tracking-wider">Timeline</p>
          </div>
          <p className="mt-2 text-sm text-gray-300">
            Pergunte ao agente sobre janelas criticas, soundchecks e horarios de show.
          </p>
        </div>
      </div>

      {messages.length > 0 && (
        <div className="mt-5 max-h-80 space-y-3 overflow-y-auto rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          {messages.map((msg, index) => (
            <div key={index} className={`flex ${msg.role === "user" ? "justify-end" : "justify-start"}`}>
              <div
                className={`max-w-[85%] rounded-2xl px-4 py-3 text-sm ${
                  msg.role === "user"
                    ? "bg-emerald-800/30 text-emerald-100"
                    : "bg-slate-800/60 text-gray-200"
                }`}
              >
                <pre className="whitespace-pre-wrap font-sans">{msg.content}</pre>
              </div>
            </div>
          ))}
          {loading && (
            <div className="flex justify-start">
              <div className="rounded-2xl bg-slate-800/60 px-4 py-3 text-sm text-gray-400">
                Analisando...
              </div>
            </div>
          )}
        </div>
      )}

      <div className="mt-5 flex gap-3">
        <input
          type="text"
          value={question}
          onChange={(e) => setQuestion(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && !loading && handleAsk()}
          placeholder="Pergunte sobre logistica, custos, alertas ou timeline dos artistas..."
          className="flex-1 rounded-2xl border border-gray-700 bg-gray-950/60 px-4 py-3 text-sm text-gray-200 placeholder:text-gray-500 focus:border-emerald-600 focus:outline-none"
          disabled={loading}
        />
        <button
          onClick={handleAsk}
          disabled={loading || !question.trim()}
          className="rounded-2xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-600 disabled:opacity-40"
        >
          <Send size={16} />
        </button>
      </div>
    </div>
  );
}
