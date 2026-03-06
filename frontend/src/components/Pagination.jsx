export default function Pagination({ page, totalPages, onPrev, onNext }) {
  return (
    <div className="flex items-center justify-between text-sm text-gray-400">
      <span>Página {page} de {totalPages}</span>
      <div className="flex gap-2">
        <button className="btn-secondary" disabled={page <= 1} onClick={onPrev}>
          Anterior
        </button>
        <button className="btn-secondary" disabled={page >= totalPages} onClick={onNext}>
          Próxima
        </button>
      </div>
    </div>
  );
}
