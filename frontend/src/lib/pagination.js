export const DEFAULT_PAGINATION_META = Object.freeze({
  page: 1,
  per_page: 25,
  total: 0,
  total_pages: 1,
});

export function extractPaginationMeta(meta, fallback = DEFAULT_PAGINATION_META) {
  const base = fallback || DEFAULT_PAGINATION_META;
  const page = Number(meta?.page || base.page || 1);
  const perPage = Number(meta?.per_page || meta?.limit || base.per_page || 25);
  const total = Number(meta?.total || base.total || 0);
  const totalPages = Number(meta?.total_pages || base.total_pages || 1);

  return {
    page: Number.isFinite(page) && page > 0 ? page : 1,
    per_page: Number.isFinite(perPage) && perPage > 0 ? perPage : 25,
    total: Number.isFinite(total) && total >= 0 ? total : 0,
    total_pages: Number.isFinite(totalPages) && totalPages > 0 ? totalPages : 1,
  };
}
