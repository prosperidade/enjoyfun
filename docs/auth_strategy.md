# Authentication Strategy

## 1. Algoritmo Adotado (HS256)
Historicamente, o sistema apresentava comentários de código referenciando **RS256** (chaves assimétricas). Contudo, a implementação estruturada de produção rodando no helper `JWT.php` utiliza **HS256** (chave simétrica HMAC), valendo-se da variável de ambiente `JWT_SECRET`.
Esta decisão foi oficializada e consolidada para o runtime atual. O uso de HS256 segue sendo a estratégia ativa em produção neste momento.

## 1.1 Roadmap oficial

- O destino de hardening para access token passa a ser assinatura assimétrica.
- Algoritmo preferido: `EdDSA` (`Ed25519`).
- Fallback operacional aceito: `RS256`.
- Essa migração ainda **não** foi implementada no runtime; ela foi planejada e documentada para rollout controlado.
- Referências canônicas:
  - `docs/adr_auth_jwt_strategy_v1.md`
  - `docs/plano_migracao_jwt_assimetrico_v1.md`

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

## 5. Observação operacional

- `JWT_SECRET` não pode ser removido automaticamente junto com a futura troca do algoritmo de access token.
- Hoje ele ainda aparece como fallback em OTP e em fluxos auxiliares de cifragem.
- A limpeza dessas dependências fica em frente separada do rollout assimétrico do JWT.

*Documento mantido pela arquitetura estrutural da EnjoyFun.*
