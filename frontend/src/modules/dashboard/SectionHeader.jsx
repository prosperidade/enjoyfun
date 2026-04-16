export default function SectionHeader({
  icon: Icon,
  title,
  badge,
  iconClassName,
  badgeClassName,
  description,
}) {
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2 border-b border-slate-800/40 pb-2">
        {Icon && (
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-cyan-500/10">
            <Icon size={18} className={iconClassName} />
          </div>
        )}
        <h2 className="text-lg font-semibold text-slate-200">{title}</h2>
        {badge && (
          <span className={`ml-2 rounded-full px-3 py-1 text-xs font-medium ${badgeClassName}`}>
            {badge}
          </span>
        )}
      </div>
      {description && <p className="text-sm text-slate-400">{description}</p>}
    </div>
  );
}
