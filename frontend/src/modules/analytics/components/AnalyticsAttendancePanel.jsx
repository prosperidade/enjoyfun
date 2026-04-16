import AnalyticsStateBox from "./AnalyticsStateBox";

const ATTENDANCE_REASON_LABELS = {
  attendance_requires_event_id:
    "Selecione um evento para habilitar presenca e no-show por categoria.",
  attendance_base_unavailable:
    "A base de presenca ainda nao esta disponivel de forma confiavel neste ambiente.",
  attendance_no_data_for_event:
    "Este evento nao trouxe participantes ou convidados suficientes para montar o bloco.",
};

function attendanceDescription(attendance) {
  return (
    ATTENDANCE_REASON_LABELS[attendance?.reason] ||
    "O bloco permanece protegido enquanto a base nao sustentar uma leitura segura."
  );
}

export default function AnalyticsAttendancePanel({ attendance }) {
  if (!attendance?.enabled) {
    return (
      <AnalyticsStateBox
        tone={attendance?.reason === "attendance_base_unavailable" ? "warning" : "info"}
        title="Attendance indisponivel neste recorte"
        description={attendanceDescription(attendance)}
      />
    );
  }

  if (!attendance?.categories?.length) {
    return (
      <AnalyticsStateBox
        tone="info"
        title="Attendance sem categorias exibiveis"
        description="A base foi considerada valida, mas este recorte nao retornou categorias consolidadas para mostrar."
      />
    );
  }

  const sources = attendance?.consistency?.sources || [];
  const guestSource = attendance?.consistency?.guest_source;

  return (
    <div className="card">
      <div className="mb-4">
        <h3 className="section-title mb-0">Presenca e No-Show por Categoria</h3>
        <p className="mt-1 text-sm text-slate-400">
          Bloco ativo apenas quando o backend confirma base consistente para o evento filtrado.
        </p>
        <div className="mt-2 inline-flex rounded-full border border-emerald-700/40 bg-emerald-950/20 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-200">
          Ativo
        </div>
        {sources.length ? (
          <p className="mt-2 text-xs text-slate-500">
            Fontes consolidadas: {sources.join(", ")}.
            {guestSource ? ` Fonte principal de convidados: ${guestSource}.` : ""}
          </p>
        ) : null}
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Categoria</th>
              <th>Confirmados</th>
              <th>Presentes</th>
              <th>No-show</th>
            </tr>
          </thead>
          <tbody>
            {attendance.categories.map((item) => (
              <tr key={item.category}>
                <td>{item.label}</td>
                <td>{Number(item.confirmed || 0).toLocaleString("pt-BR")}</td>
                <td>{Number(item.present || 0).toLocaleString("pt-BR")}</td>
                <td>{Number(item.no_show || 0).toLocaleString("pt-BR")}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
