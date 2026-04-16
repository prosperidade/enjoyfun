import { Link } from "react-router-dom";
import { ArrowUpRight } from "lucide-react";
import { useEventScope } from "../../context/EventScopeContext";

export default function StatCard({
  icon: Icon,
  label,
  value,
  color,
  to,
  loading,
  subtitle,
  scopeEventId,
  compact = false,
}) {
  const { buildScopedPath } = useEventScope();
  const baseClassName = compact
    ? "an-stat-card group relative overflow-hidden min-h-[170px]"
    : "an-stat-card group relative overflow-hidden";
  const target = to ? buildScopedPath(to, scopeEventId) : null;

  const content = (
    <>
      <div className={`absolute top-0 right-0 h-24 w-24 rounded-full mix-blend-overlay opacity-5 blur-2xl ${color} -mr-8 -mt-8`} />
      <div className={`mb-3 flex h-10 w-10 items-center justify-center rounded-xl ${color}/15`}>
        {Icon && <Icon size={20} className={`${color.replace('bg-', 'text-').replace('-600', '-400').replace('-700', '-400')}`} />}
      </div>
      <div className="text-2xl font-bold text-slate-100">{loading ? "—" : value}</div>
      <div className="text-sm text-slate-400">{label}</div>
      {subtitle && <div className="mt-1 text-[10px] text-slate-500">{subtitle}</div>}
      {target && (
        <ArrowUpRight
          size={14}
          className="mt-auto self-end text-slate-600 transition-colors group-hover:text-cyan-400"
        />
      )}
    </>
  );

  if (target) {
    return <Link to={target} className={baseClassName}>{content}</Link>;
  }

  return <div className={baseClassName}>{content}</div>;
}
