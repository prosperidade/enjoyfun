import { ArrowLeft, Wifi, WifiOff } from "lucide-react";
import { useEventScope } from "../../../context/EventScopeContext";

export default function PosHeader({ isOffline, navigate, sectorInfo }) {
  const { buildScopedPath } = useEventScope();

  return (
    <header className="h-16 px-8 flex justify-between items-center bg-slate-950/80 backdrop-blur-md sticky top-0 z-50 shadow-[0_4px_20px_rgba(0,0,0,0.5)]">
      <div className="flex items-center gap-8">
        <button
          onClick={() => navigate(buildScopedPath("/pos"))}
          className="p-2 text-slate-400 hover:text-slate-100 hover:bg-slate-800/40 rounded-full transition-all"
          title="Voltar ao PDV"
        >
          <ArrowLeft size={20} />
        </button>

        <div className="text-2xl font-black text-cyan-400 tracking-tighter font-headline" style={{ textShadow: '0 0 10px rgba(0,240,255,0.3)' }}>
          PDV {sectorInfo.title}
        </div>

        <div className="flex items-center gap-2 px-3 py-1 bg-cyan-500/10 border border-cyan-500/20 rounded-full">
          <span className="w-2 h-2 bg-cyan-400 rounded-full animate-pulse" />
          <span className="text-cyan-400 text-[10px] font-bold tracking-widest uppercase">
            {sectorInfo.title} SECTOR LIVE
          </span>
        </div>
      </div>

      <div className="flex items-center gap-4">
        {isOffline ? (
          <div className="flex items-center gap-2 px-4 py-2 bg-red-500/10 border border-red-500/30 rounded-full text-red-400 text-xs font-black tracking-widest uppercase animate-pulse">
            <WifiOff size={16} /> OFFLINE MODE
          </div>
        ) : (
          <div className="flex items-center gap-2 px-3 py-1.5 bg-green-500/10 border border-green-500/20 rounded-full text-green-400 text-[10px] font-bold tracking-wider">
            <Wifi size={14} /> ONLINE
          </div>
        )}
      </div>
    </header>
  );
}
