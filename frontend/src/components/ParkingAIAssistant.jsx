import { useState } from "react";
import toast from "react-hot-toast";
import { Bot, Send, TrafficCone } from "lucide-react";
import api from "../lib/api";

export default function ParkingAIAssistant({ eventId, eventName, parkedCount, pendingCount }) {
  const [question, setQuestion] = useState("");
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(false);

  const handleAsk = async () => {
    const trimmedQuestion = question.trim();
    const normalizedEventId = Number(eventId);

    if (!trimmedQuestion) {
      return;
    }
    if (normalizedEventId <= 0) {
      toast.error("Selecione um evento para consultar o agente de logistica.");
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
          event_name: eventName || null,
          surface: "parking",
          agent_key: "logistics",
          parked_total_hint: Number(parkedCount || 0),
          pending_total_hint: Number(pendingCount || 0),
        },
      });

      setMessages((current) => [
        ...current,
        {
          role: "ai",
          content: response.data?.data?.insight || "Sem resposta do agente de logistica.",
        },
      ]);
    } catch (error) {
      const message = error.response?.data?.message || "Erro ao consultar o agente de logistica.";
      setMessages((current) => [...current, { role: "ai", content: `Erro: ${message}` }]);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card border-cyan-800/40 bg-[linear-gradient(135deg,_rgba(8,47,73,0.30),_rgba(15,23,42,0.88))] p-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <div className="flex items-center gap-2 text-cyan-200">
            <Bot size={18} />
            <p className="font-semibold">Agente de Logistica embutido</p>
          </div>
          <p className="mt-2 text-sm text-gray-300 max-w-2xl">
            Assistente operacional da portaria. Ele usa o contexto real do estacionamento do evento
            atual para apontar gargalos, pendencias de bip e proximas acoes.
          </p>
        </div>
        <div className="rounded-2xl border border-cyan-900/50 bg-slate-950/45 px-4 py-3 text-xs text-cyan-100">
          <p className="uppercase tracking-[0.24em] text-cyan-300">Contexto atual</p>
          <p className="mt-2">Veiculos no local: {Number(parkedCount || 0)}</p>
          <p>Pendentes: {Number(pendingCount || 0)}</p>
          <p>Evento: {eventName || "nao selecionado"}</p>
        </div>
      </div>

      <div className="mt-5 rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
        <div className="flex gap-3">
          <textarea
            rows={3}
            className="input min-h-[96px] flex-1"
            placeholder="Ex.: Existe gargalo de entrada agora? O que devo ajustar na portaria?"
            value={question}
            onChange={(event) => setQuestion(event.target.value)}
          />
          <button
            type="button"
            className="btn-primary self-stretch px-5"
            disabled={loading}
            onClick={handleAsk}
          >
            <span className="flex items-center gap-2">
              {loading ? <TrafficCone size={16} className="animate-pulse" /> : <Send size={16} />}
              {loading ? "Analisando..." : "Perguntar"}
            </span>
          </button>
        </div>
      </div>

      <div className="mt-5 space-y-3">
        {messages.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-gray-800 bg-gray-950/60 p-5 text-sm text-gray-400">
            Nenhuma consulta ainda. O agente entra em cena quando houver um evento selecionado e
            uma pergunta operacional.
          </div>
        ) : (
          messages.map((message, index) => (
            <div
              key={`${message.role}-${index}`}
              className={`rounded-2xl border p-4 ${
                message.role === "user"
                  ? "border-cyan-900/50 bg-cyan-950/20 text-cyan-50"
                  : "border-gray-800 bg-gray-950/70 text-gray-200"
              }`}
            >
              <p className="text-[11px] uppercase tracking-[0.24em] opacity-70 mb-2">
                {message.role === "user" ? "Operador" : "Agente"}
              </p>
              <p className="text-sm whitespace-pre-wrap leading-relaxed">{message.content}</p>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
