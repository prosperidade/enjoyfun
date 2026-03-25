<?php
/**
 * audit_artist_logistics_payables.php
 * Job de Auditoria para detectar divergências de contas a pagar de cachê de artistas e itens logísticos.
 */

require_once __DIR__ . '/../config/Database.php';

$db = Database::getInstance();

echo "Iniciando auditoria de logistica de artistas vs. financeiro...\n\n";

// 1. Artistas com cache_amount > 0 sem event_payables
echo "1. Checando cachês sem contas a pagar vinculadas...\n";
$stmtCache = $db->query("
    SELECT ea.id AS event_artist_id, ea.event_id, a.stage_name, ea.cache_amount
    FROM event_artists ea
    JOIN artists a ON a.id = ea.artist_id
    WHERE ea.cache_amount > 0
");
$caches = $stmtCache->fetchAll(PDO::FETCH_ASSOC);

$missingCaches = 0;
foreach ($caches as $cache) {
    $stmtPay = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_payable
        FROM event_payables
        WHERE event_artist_id = :ea_id
          AND source_type = 'artist'
          AND status <> 'cancelled'
    ");
    $stmtPay->execute([':ea_id' => $cache['event_artist_id']]);
    $totalPayable = (float) $stmtPay->fetchColumn();
    $cacheAmount = (float) $cache['cache_amount'];

    if ($totalPayable < $cacheAmount) {
        $missingCaches++;
        echo sprintf(" [ALERTA] Evento %d | Artista: %s | Cachê: %.2f | Payables (artist): %.2f (Falta: %.2f)\n",
            $cache['event_id'], $cache['stage_name'], $cacheAmount, $totalPayable, $cacheAmount - $totalPayable);
    }
}
if ($missingCaches === 0) {
    echo " Nenhum problema encontrado em cachês.\n";
}
echo "\n";

// 2. Itens logísticos com custo total_amount > 0 sem event_payables correspondente
echo "2. Checando itens logísticos com custo sem contas a pagar...\n";
$stmtLog = $db->query("
    SELECT ali.event_artist_id, ea.event_id, a.stage_name, COALESCE(SUM(ali.total_amount), 0) as total_logistics
    FROM artist_logistics_items ali
    JOIN event_artists ea ON ea.id = ali.event_artist_id
    JOIN artists a ON a.id = ea.artist_id
    WHERE ali.total_amount > 0
    GROUP BY ali.event_artist_id, ea.event_id, a.stage_name
");
$logistics = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

$missingLogistics = 0;
foreach ($logistics as $logItem) {
    $stmtPay = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_payable
        FROM event_payables
        WHERE event_artist_id = :ea_id
          AND source_type = 'logistics'
          AND status <> 'cancelled'
    ");
    $stmtPay->execute([':ea_id' => $logItem['event_artist_id']]);
    $totalPayable = (float) $stmtPay->fetchColumn();
    $logAmount = (float) $logItem['total_logistics'];

    if ($totalPayable < $logAmount) {
        $missingLogistics++;
        echo sprintf(" [ALERTA] Evento %d | Artista: %s | Custo logístico: %.2f | Payables (logistics): %.2f (Falta: %.2f)\n",
            $logItem['event_id'], $logItem['stage_name'], $logAmount, $totalPayable, $logAmount - $totalPayable);
    }
}
if ($missingLogistics === 0) {
    echo " Nenhum problema encontrado em logística.\n";
}

echo "\nAuditoria concluída!\n";
