<?php
try {
    $db = new PDO('sqlite:' . __DIR__ . '/enjoyfun.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "====================================\n";
    echo "1. VERIFICANDO EVENTOS\n";
    echo "====================================\n";
    $stmt = $db->query("SELECT id, name FROM events LIMIT 3");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$events) {
        die("Sem eventos no banco local.\n");
    }
    
    foreach ($events as $ev) {
        $eventId = $ev['id'];
        echo "\nTestando Evento $eventId : {$ev['name']}\n";
        
        $stmt2 = $db->prepare("SELECT COUNT(*) FROM event_days WHERE event_id = ?");
        $stmt2->execute([$eventId]);
        $days = $stmt2->fetchColumn();
        echo "-> Dias do Evento (event_days): $days\n";

        $stmt3 = $db->prepare("SELECT COUNT(*) FROM event_shifts es JOIN event_days ed ON es.event_day_id = ed.id WHERE ed.event_id = ?");
        $stmt3->execute([$eventId]);
        $shifts = $stmt3->fetchColumn();
        echo "-> Turnos do Evento (event_shifts): $shifts\n";
        
        $stmt4 = $db->prepare("SELECT COUNT(*) FROM workforce_assignments WHERE event_id = ?");
        $stmt4->execute([$eventId]);
        $wfCount = $stmt4->fetchColumn();
        echo "-> Assignments do evento no Workforce: $wfCount\n";
    }

    echo "\n====================================\n";
    echo "2. VERIFICANDO SCHEMA (MEAL_UNIT_COST)\n";
    echo "====================================\n";
    $stmt = $db->query("PRAGMA table_info('organizer_financial_settings')");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasCol = false;
    foreach ($cols as $col) {
        if ($col['name'] === 'meal_unit_cost') {
            $hasCol = true;
            break;
        }
    }
    if ($hasCol) {
        echo "-> A coluna 'meal_unit_cost' EXISTE na tabela organizer_financial_settings.\n";
    } else {
        echo "-> A coluna 'meal_unit_cost' NÃO EXISTE na tabela organizer_financial_settings.\n";
    }

    echo "\nTESTE CONCLUIDO.\n";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
