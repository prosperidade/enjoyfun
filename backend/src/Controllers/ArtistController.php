<?php

require_once __DIR__ . '/../Helpers/ArtistControllerSupport.php';
require_once __DIR__ . '/../Helpers/ArtistCatalogBookingHelper.php';
require_once __DIR__ . '/../Helpers/ArtistLogisticsHelper.php';
require_once __DIR__ . '/../Helpers/ArtistOperationsHelper.php';
require_once __DIR__ . '/../Helpers/ArtistTeamFilesHelper.php';
require_once __DIR__ . '/../Helpers/ArtistImportHelper.php';
require_once __DIR__ . '/../Helpers/ArtistExportHelper.php';

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $numericId = artistIsNumericIdentifier($id);
    $numericSub = artistIsNumericIdentifier($sub);

    match (true) {
        $method === 'GET' && $id === 'module-status' && $sub === null => getArtistModuleStatus(),

        $method === 'GET' && $id === null && $sub === null => listArtists($query),
        $method === 'POST' && $id === null && $sub === null => createArtist($body),
        $method === 'GET' && $numericId && $sub === null => getArtist((int)$id, $query),
        ($method === 'PUT' || $method === 'PATCH') && $numericId && $sub === null => updateArtist((int)$id, $body),

        $method === 'GET' && $id === 'bookings' && $sub === null => listArtistBookings($query),
        $method === 'POST' && $id === 'bookings' && $sub === null => createArtistBooking($body),
        $method === 'GET' && $id === 'bookings' && $numericSub && $subId === null => getArtistBooking((int)$sub),
        ($method === 'PUT' || $method === 'PATCH') && $id === 'bookings' && $numericSub && $subId === null => updateArtistBooking((int)$sub, $body),
        $method === 'POST' && $id === 'bookings' && $numericSub && $subId === 'cancel' => cancelArtistBooking((int)$sub, $body),

        $method === 'GET' && $id === 'logistics' && $sub === null => listArtistLogistics($query),
        $method === 'POST' && $id === 'logistics' && $sub === null => createArtistLogistics($body),
        $method === 'GET' && $id === 'logistics' && $numericSub && $subId === null => getArtistLogistics((int)$sub),
        ($method === 'PUT' || $method === 'PATCH') && $id === 'logistics' && $numericSub && $subId === null => updateArtistLogistics((int)$sub, $body),

        $method === 'GET' && $id === 'logistics-items' && $sub === null => listArtistLogisticsItems($query),
        $method === 'POST' && $id === 'logistics-items' && $sub === null => createArtistLogisticsItem($body),
        $method === 'GET' && $id === 'logistics-items' && $numericSub && $subId === null => getArtistLogisticsItem((int)$sub),
        ($method === 'PUT' || $method === 'PATCH') && $id === 'logistics-items' && $numericSub && $subId === null => updateArtistLogisticsItem((int)$sub, $body),
        $method === 'DELETE' && $id === 'logistics-items' && $numericSub && $subId === null => deleteArtistLogisticsItem((int)$sub),

        $method === 'GET' && $id === 'timelines' && $sub === null => listArtistTimelines($query),
        $method === 'POST' && $id === 'timelines' && $sub === null => createArtistTimeline($body),
        $method === 'GET' && $id === 'timelines' && $numericSub && $subId === null => getArtistTimeline((int)$sub),
        ($method === 'PUT' || $method === 'PATCH') && $id === 'timelines' && $numericSub && $subId === null => updateArtistTimeline((int)$sub, $body),
        $method === 'POST' && $id === 'timelines' && $numericSub && $subId === 'recalculate' => recalculateArtistTimeline((int)$sub),

        $method === 'GET' && $id === 'transfers' && $sub === null => listArtistTransfers($query),
        $method === 'POST' && $id === 'transfers' && $sub === null => createArtistTransfer($body),
        $method === 'GET' && $id === 'transfers' && $numericSub && $subId === null => getArtistTransfer((int)$sub),
        ($method === 'PUT' || $method === 'PATCH') && $id === 'transfers' && $numericSub && $subId === null => updateArtistTransfer((int)$sub, $body),
        $method === 'DELETE' && $id === 'transfers' && $numericSub && $subId === null => deleteArtistTransfer((int)$sub),

        $method === 'GET' && $id === 'alerts' && $sub === null => listArtistAlerts($query),
        $method === 'POST' && $id === 'alerts' && $sub === 'recalculate' && $subId === null => recalculateArtistAlerts($body, $query),
        $method === 'GET' && $id === 'alerts' && $numericSub && $subId === null => getArtistAlert((int)$sub),
        $method === 'PATCH' && $id === 'alerts' && $numericSub && $subId === null => updateArtistAlert((int)$sub, $body),
        $method === 'POST' && $id === 'alerts' && $numericSub && $subId === 'acknowledge' => acknowledgeArtistAlert((int)$sub, $body),
        $method === 'POST' && $id === 'alerts' && $numericSub && $subId === 'resolve' => resolveArtistAlert((int)$sub, $body),

        $method === 'GET' && $id === 'team' && $sub === null => listArtistTeamMembers($query),
        $method === 'POST' && $id === 'team' && $sub === null => createArtistTeamMember($body),
        $method === 'GET' && $id === 'team' && $numericSub && $subId === null => getArtistTeamMember((int)$sub),
        ($method === 'PUT' || $method === 'PATCH') && $id === 'team' && $numericSub && $subId === null => updateArtistTeamMember((int)$sub, $body),
        $method === 'DELETE' && $id === 'team' && $numericSub && $subId === null => deleteArtistTeamMember((int)$sub),

        $method === 'GET' && $id === 'files' && $sub === null => listArtistFiles($query),
        $method === 'POST' && $id === 'files' && $sub === null => createArtistFile($body),
        $method === 'GET' && $id === 'files' && $numericSub && $subId === null => getArtistFile((int)$sub),
        $method === 'DELETE' && $id === 'files' && $numericSub && $subId === null => deleteArtistFile((int)$sub),

        $method === 'POST' && $id === 'imports' && $sub === 'preview' && $subId === null => previewArtistImport($body),
        $method === 'POST' && $id === 'imports' && $sub === 'confirm' && $subId === null => confirmArtistImport($body),
        $method === 'GET' && $id === 'imports' && $numericSub && $subId === null => getArtistImportBatch((int)$sub),

        $method === 'POST' && $id === 'exports' && $sub === 'operation' && $subId === null => exportArtistOperation($body, $query),

        default => jsonError('Endpoint de Artistas nao encontrado.', 404),
    };
}

function getArtistModuleStatus(): void
{
    [$db, $organizerId] = getArtistContext();

    $tables = artistModuleSchemaStatus($db);
    $schemaReady = true;
    foreach ($tables as $row) {
        if (empty($row['exists'])) {
            $schemaReady = false;
            break;
        }
    }

    jsonSuccess([
        'module' => 'artists',
        'organizer_id' => $organizerId,
        'resource_root' => '/api/artists',
        'current_phase' => 'phase_5_p1',
        'schema_ready' => $schemaReady,
        'required_tables' => $tables,
        'implemented_subresources' => [
            'artists',
            'bookings',
            'logistics',
            'logistics-items',
            'timelines',
            'transfers',
            'alerts',
            'team',
            'files',
            'imports',
            'exports',
        ],
        'pending_subresources' => [],
        'internal_financial_scope' => [
            'cache_amount',
            'artist_logistics_items',
            'artist_cost_rollup',
        ],
    ], 'Status do modulo de logistica de artistas carregado com sucesso.');
}

function respondArtistPendingFeature(string $feature): void
{
    [$db] = getArtistContext();
    artistEnsureModuleSchemaReady($db);

    jsonError(
        'Endpoint de ' . $feature . ' ainda nao foi implementado nesta rodada do modulo de logistica de artistas.',
        501,
        [
            'module' => 'artists',
            'current_phase' => 'phase_5_p1',
            'pending_feature' => $feature,
        ]
    );
}
