import { ArrowLeft, Wifi, WifiOff } from "lucide-react";
import { useEventScope } from "../../../context/EventScopeContext";

export default function PosHeader({ isOffline, navigate, sectorInfo }) {
  const { buildScopedPath } = useEventScope();

  return (
    <header className="h-16 px-6 border-b border-gray-800 flex items-center justify-between bg-gray-950/50 backdrop-blur-md sticky top-0 z-50">
      <div className="flex items-center gap-4">
        <button
          onClick={() => navigate(buildScopedPath("/"))}
          className="p-2 text-gray-400 hover:text-white hover:bg-gray-800 rounded-full transition-all flex items-center justify-center"
          title="Voltar ao Dashboard"
        >
          <ArrowLeft size={20} />
        </button>

        <div className="flex items-center gap-3">
          <div className="p-2 bg-gray-900 rounded-lg border border-gray-800">
            {sectorInfo.icon}
          </div>
          <div>
            <h1 className="font-bold text-sm tracking-widest text-white uppercase">
              PDV {sectorInfo.title}
            </h1>
            <p className="text-[10px] text-gray-500 font-medium uppercase tracking-tighter">
              EnjoyFun v2.0 • Unidade Digital
            </p>
          </div>
        </div>
      </div>

      <div className="flex items-center gap-3">
        {isOffline ? (
          <div className="flex items-center gap-2 px-4 py-2 bg-red-500/10 border border-red-500/30 rounded-full text-red-400 text-xs font-black tracking-widest uppercase shadow-[0_0_15px_rgba(239,68,68,0.2)] animate-pulse">
            <WifiOff size={16} /> <span className="mt-0.5">OFFLINE MODE</span>
          </div>
        ) : (
          <div className="flex items-center gap-2 px-3 py-1.5 bg-green-500/10 border border-green-500/20 rounded-full text-green-400 text-[10px] font-bold tracking-wider">
            <Wifi size={14} /> <span>ONLINE</span>
          </div>
        )}
      </div>
    </header>
  );
}
