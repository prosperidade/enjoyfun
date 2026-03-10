import AnalyticsStateBox from "./AnalyticsStateBox";

export default function AnalyticsRankingPanel({
  columns,
  emptyMessage,
  items,
  loading,
  title,
}) {
  return (
    <div className="card">
      <h3 className="section-title">{title}</h3>

      {loading ? (
        <div className="flex h-56 items-center justify-center">
          <div className="spinner h-8 w-8" />
        </div>
      ) : items?.length ? (
        <div className="table-wrapper">
          <table className="table">
            <thead>
              <tr>
                {columns.map((column) => (
                  <th key={column.key}>{column.label}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.map((item, index) => (
                <tr key={`${item[columns[0].key]}-${index}`}>
                  {columns.map((column) => (
                    <td key={column.key}>
                      {column.render ? column.render(item[column.key], item) : item[column.key]}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <AnalyticsStateBox
          compact
          title={`${title} sem dados`}
          description={emptyMessage}
        />
      )}
    </div>
  );
}
