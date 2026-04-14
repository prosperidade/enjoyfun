---
name: php-modern
description: >
  Boas práticas PHP 8.2+ para o backend EnjoyFun. Use ao escrever ou refatorar
  código PHP, criar services, controllers, helpers. Trigger: PHP, backend,
  service, controller, refactor PHP, tipo PHP.
---

# PHP 8.2+ — EnjoyFun Backend

## Padrões Obrigatórios

### Tipos
- Return types em todos os métodos públicos
- Parameter types sempre declarados
- `?Type` para nullable, nunca `Type|null` (consistência com codebase existente)
- `array` com docblock `@param array<string, mixed>` quando complexo

### Classes & Services
```php
class ExampleService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly int $organizerId
    ) {}

    public function listItems(): array
    {
        // organizer_id via RLS, não WHERE manual
        $stmt = $this->pdo->query('SELECT * FROM items');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
```

### Padrões EnjoyFun
- Services recebem `$pdo` e `$organizerId` no construtor
- Controllers extraem `$organizerId` do JWT via `AuthMiddleware`
- `Database::getConnection()` já faz `SET app.current_organizer_id`
- Erros: `throw new \RuntimeException(message, code)` — controller converte em HTTP
- JSON output: `json_encode($data, JSON_UNESCAPED_UNICODE)`

### Proibido
- `eval()`, `exec()` com input do usuário
- `$_GET/$_POST` dentro de Services (só no Controller)
- `echo` / `print` fora de controllers de output
- `mysql_*` funções (usamos PostgreSQL)
- Queries sem prepared statements

### Validação
- Sempre rodar `php -l arquivo.php` após edição
- Smoke test quando existir: `php scripts/*_smoke.php`
