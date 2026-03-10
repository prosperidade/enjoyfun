const TONE_STYLES = {
  neutral: "border-dashed border-gray-700 bg-gray-900/30 text-gray-300",
  info: "border-sky-800/40 bg-sky-950/20 text-sky-100",
  warning: "border-amber-700/40 bg-amber-950/20 text-amber-100",
  danger: "border-rose-700/40 bg-rose-950/20 text-rose-100",
  success: "border-emerald-700/40 bg-emerald-950/20 text-emerald-100",
};

export default function AnalyticsStateBox({
  compact = false,
  description,
  title,
  tone = "neutral",
}) {
  const spacing = compact ? "px-4 py-3" : "px-5 py-4";

  return (
    <div className={`rounded-2xl border ${spacing} ${TONE_STYLES[tone] || TONE_STYLES.neutral}`}>
      {title ? <h3 className="text-sm font-semibold">{title}</h3> : null}
      {description ? (
        <p className={`text-sm ${title ? "mt-1" : ""}`}>{description}</p>
      ) : null}
    </div>
  );
}
