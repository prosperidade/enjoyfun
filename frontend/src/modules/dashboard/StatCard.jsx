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
    ? "stat-card group relative overflow-hidden min-h-[170px]"
    : "stat-card group relative overflow-hidden";
  const target = to ? buildScopedPath(to, scopeEventId) : null;

  const content = (
    <>
      <div className={`absolute top-0 right-0 h-24 w-24 rounded-full mix-blend-overlay opacity-10 blur-2xl ${color} -mr-8 -mt-8`} />
      <div className={`mb-3 flex h-10 w-10 items-center justify-center rounded-xl ${color}`}>
        {Icon && <Icon size={20} className="text-white" />}
      </div>
      <div className="stat-value">{loading ? "—" : value}</div>
      <div className="stat-label">{label}</div>
      {subtitle && <div className="mt-1 text-[10px] text-gray-500">{subtitle}</div>}
      {target && (
        <ArrowUpRight
          size={14}
          className="mt-auto self-end text-gray-600 transition-colors group-hover:text-white"
        />
      )}
    </>
  );

  if (target) {
    return <Link to={target} className={baseClassName}>{content}</Link>;
  }

  return <div className={baseClassName}>{content}</div>;
}
