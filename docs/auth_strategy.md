# Authentication Strategy

## 1. Algoritmo Adotado (HS256)
Historicamente, o sistema apresentava comentários de código referenciando **RS256** (chaves assimétricas). Contudo, a implementação estruturada de produção rodando no helper `JWT.php` utiliza **HS256** (chave simétrica HMAC), valendo-se da variável de ambiente `JWT_SECRET`.
Esta decisão foi oficializada e consolidada. O uso de HS256 é extremamente eficaz e possui alta performance para ambientes SaaS isolados. Qualquer menção a RS256 no código foi extirpada para evitar drift documental.

## 2. Estrutura do Payload
Todos os tokens JWT de acesso (`access_token`) garantem o seguinte contrato base (claims):
- `sub`: ID do usuário.
- `name`: Nome legível.
- `email`: E-mail oficial.
- `role`: Nível hierárquico principal (`admin`, `organizer`, `manager`, `staff`, `customer`).
- `roles`: Backwards-compatibility para o array de permissões do frontend legado.
- `sector`: Setor operacional alocado (`all` para gestores gerais).
- **`organizer_id`**: Chave de restrição Multi-tenant. Absolutamente vital para o White Label. O usuário cliente isola suas requisições baseadas nisso.
- `iat` e `exp`: Padrões da RFC.

## 3. Fluxo de Refresh
- Refresh tokens são emitidos como hashes longos randômicos `bin2hex(random_bytes(32))` e armazenados na tabela `refresh_tokens`.
- Possuem `expires_at` longo configurado em `JWT_REFRESH` (ex: 30 dias).
- Trocar um access token por um novo consome o `refresh_token` atual (ele é destruído no DB) e devolve um novo par (Rotating Refresh Tokens).

## 4. Remoção de Bypasses
Não há credenciais ou escapes (`bypass`) em hardcode ativos nos métodos do `AuthController`. Todo login passa compulsoriamente por verificação na tabela `users` (`password_verify` para senhas e `hash_equals` longo na verificação do `JWT_SECRET`).

*Documento mantido pela arquitetura estrutural da EnjoyFun.*
