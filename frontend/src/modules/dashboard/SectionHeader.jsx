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
      <div className="flex items-center gap-2 border-b border-gray-800 pb-2">
        {Icon && <Icon size={20} className={iconClassName} />}
        <h2 className="text-lg font-semibold text-white">{title}</h2>
        {badge && (
          <span className={`ml-2 rounded px-2 py-1 text-xs font-medium ${badgeClassName}`}>
            {badge}
          </span>
        )}
      </div>
      {description && <p className="text-sm text-gray-400">{description}</p>}
    </div>
  );
}
