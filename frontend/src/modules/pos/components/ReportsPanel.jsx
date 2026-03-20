export default function ReportsPanel({ children, isActive }) {
  return (
    <div
      className={`space-y-6 ${isActive ? "block" : "invisible h-0 overflow-hidden pointer-events-none"}`}
      aria-hidden={!isActive}
    >
      {children}
    </div>
  );
}
