---
name: redis-pubsub
description: >
  Redis 7 para cache, rate limiting e pub/sub SSE streaming na EnjoyFun.
  Use ao implementar cache, real-time updates, rate limiting, ou SSE.
  Trigger: Redis, cache, pub/sub, SSE, streaming, rate limit, real-time.
---

# Redis — EnjoyFun

## Casos de Uso

### Cache de Snapshots
```php
$redis->setex("dashboard:{$organizerId}:{$eventId}", 60, json_encode($data));
$cached = $redis->get("dashboard:{$organizerId}:{$eventId}");
```
- Key pattern: `{domínio}:{organizer_id}:{recurso_id}`
- TTL: 60s para dashboards, 300s para relatórios, 10s para PDV

### Rate Limiting
```php
$key = "ratelimit:{$organizerId}:{$endpoint}";
$count = $redis->incr($key);
if ($count === 1) $redis->expire($key, 60);
if ($count > $limit) throw new \RuntimeException('Rate limit exceeded', 429);
```

### Pub/Sub para SSE (Sprint 6)
```php
// Publisher (backend após tool execution)
$redis->publish("ai:stream:{$organizerId}", json_encode([
    'type' => 'token',
    'content' => $chunk,
    'execution_id' => $executionId
]));

// Subscriber (SSE endpoint)
$redis->subscribe(["ai:stream:{$organizerId}"], function($redis, $channel, $message) {
    echo "data: {$message}\n\n";
    ob_flush(); flush();
});
```

## Regras
- Keys sempre incluem `organizer_id` (isolamento de tenant)
- TTL obrigatório em toda key (nunca keys eternas)
- Serialização: `json_encode` / `json_decode`
- Fallback graceful: se Redis indisponível, funcionalidade degrada mas não quebra
