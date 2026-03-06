<?php
function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void {
    jsonSuccess(['status' => 'ok', 'timestamp' => time()]);
}