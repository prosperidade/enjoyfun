<?php

function enjoyNormalizePagination(array $query, int $defaultPerPage = 20, int $maxPerPage = 100): array
{
    $page = max(1, (int)($query['page'] ?? 1));
    $perPageRaw = $query['per_page'] ?? $query['limit'] ?? $defaultPerPage;
    $perPage = max(1, min($maxPerPage, (int)$perPageRaw));

    return [
        'page' => $page,
        'per_page' => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

function enjoyBuildPaginationMeta(int $page, int $perPage, int $total): array
{
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => max(1, (int)ceil($total / max($perPage, 1))),
    ];
}

function enjoyBindPagination(PDOStatement $stmt, array $pagination, string $limitKey = ':limit', string $offsetKey = ':offset'): void
{
    $stmt->bindValue($limitKey, (int)($pagination['per_page'] ?? 20), PDO::PARAM_INT);
    $stmt->bindValue($offsetKey, (int)($pagination['offset'] ?? 0), PDO::PARAM_INT);
}
