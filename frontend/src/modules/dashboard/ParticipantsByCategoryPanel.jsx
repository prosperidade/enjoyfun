export default function ParticipantsByCategoryPanel({ loading, categories }) {
  return (
    <div className="card">
      <h3 className="mb-4 text-sm font-semibold text-gray-200">Participantes por Categoria</h3>
      {loading ? (
        <div className="flex h-28 items-center justify-center">
          <div className="spinner h-6 w-6" />
        </div>
      ) : categories?.length ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {categories.map((category) => (
            <div
              key={category.key}
              className="rounded-lg border border-gray-700/60 bg-gray-800/40 px-3 py-3"
            >
              <div className="text-xs uppercase tracking-wide text-gray-400">{category.label}</div>
              <div className="mt-2 text-2xl font-semibold text-white">
                {Number(category.qty || 0).toLocaleString("pt-BR")}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <p className="text-sm text-gray-500">
          Sem distribuição de participantes por categoria para o filtro atual.
        </p>
      )}
    </div>
  );
}
