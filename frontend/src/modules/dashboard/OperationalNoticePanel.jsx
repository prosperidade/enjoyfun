import { AlertTriangle } from "lucide-react";

export default function OperationalNoticePanel() {
  return (
    <div className="card flex h-full flex-col justify-center border-yellow-800/40 bg-yellow-900/10">
      <div className="flex items-start gap-3">
        <AlertTriangle size={24} className="flex-shrink-0 text-yellow-500" />
        <div>
          <p className="text-sm font-medium text-yellow-400">
            Aviso Operacional de Sincronização
          </p>
          <p className="mt-1 text-xs leading-relaxed text-gray-400">
            Certifique-se de que os terminais de PDV (Bar, Lojas) se conectem à internet ao
            final do evento para sincronizar vendas offlines enfileiradas.
          </p>
        </div>
      </div>
    </div>
  );
}
