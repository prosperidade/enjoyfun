Auditoria completa do sistema
Fiz uma auditoria técnica do repositório cobrindo backend PHP, frontend React/Vite, banco/migrations, integrações, sincronização offline e coerência documental.

Diagnóstico executivo
O sistema já tem sinais claros de hardening real em pontos importantes: escopo multi-tenant em tickets e estacionamento, sessão no frontend migrada para sessionStorage, trilha de auditoria, fila offline e serviços para mensageria/gateways. Isso aparece no código atual, não só na documentação. 

Ao mesmo tempo, encontrei 5 classes de risco relevantes:

Segurança/documentação fora de sincronia: a documentação operacional afirma RS256, mas o código implementa HS256 simétrico. Isso é um risco de operação, troubleshooting e compliance. 

Webhook de mensageria sem autenticação/assinatura: qualquer origem consegue postar no webhook e ainda informar organizer_id via query/body. Isso é o achado mais crítico. 

Drift de banco e migrations: o baseline oficial admite que a migration financeira 006 não está refletida, e o serviço de mensageria ainda cria schema em runtime, o que quebra governança e previsibilidade entre ambientes. 

Fragilidades operacionais silenciosas: login grava detalhes sensíveis em log e invalida todos os refresh tokens do usuário a cada novo login, o que derruba múltiplas sessões/dispositivos. 

Ferramental e manutenção: frontend compila, mas o bundle final ficou muito grande; além disso o lint falhou por problema de tooling/arquivos temporários. Isso reduz confiabilidade de release. 

1) Fragilidades críticas
1.1 Webhook de mensageria aceita payload sem autenticação forte
O endpoint de webhook simplesmente lê provider e organizer_id do query/body, chama captureWebhookEvent() e responde sucesso; não há verificação de assinatura, segredo compartilhado, HMAC ou allowlist de origem. 

Pior: no service, o organizer_id vindo do contexto pode prevalecer diretamente, antes mesmo da tentativa de resolver por instância. Isso permite forjar evento inbound, poluir histórico, alterar reconciliação de delivery e potencialmente mascarar fraude operacional. 

Impacto

falsificação de eventos de entrega/erro;

poluição de histórico operacional;

reconciliação incorreta de mensagens;

superfície para enumeração de tenants.

Correção recomendada

exigir X-Signature com HMAC SHA-256 por provedor/canal;

rejeitar organizer_id vindo do cliente;

resolver tenant apenas por credencial/instância registrada;

armazenar evento bruto, mas só marcar delivery como delivered/failed após validação criptográfica.

1.2 Documentação de segurança está divergente do código real
O CLAUDE.md afirma “JWT RS256 assimétrico” e “Auth Middleware RS256”. 
Mas o helper JWT implementa claramente HS256 com hash_hmac('sha256', ...) e segredo simétrico em JWT_SECRET. 

O middleware também está marcado como HS256. 

Impacto

operação e troubleshooting em cima de premissa errada;

documentação de segurança perde credibilidade;

chance de provisionar infra/chaves erradas;

auditoria externa pode considerar o ambiente em desacordo.

Correção recomendada

curto prazo: corrigir imediatamente a documentação para refletir HS256;

médio prazo: decidir formalmente entre:

ficar em HS256 com rotação forte de segredo, ou

migrar de fato para RS256/EdDSA com key rotation e kid.

1.3 Banco com drift entre baseline, migrations e bootstrap em runtime
O database/README.md reconhece explicitamente que a migration 006_financial_hardening.sql não está refletida integralmente no baseline atual para organizer_payment_gateways.is_primary e environment. 

E isso bate com o schema atual: a tabela organizer_payment_gateways no baseline não possui essas colunas. 

Ao mesmo tempo, o PaymentGatewayService já trabalha com fallback dinâmico para presença/ausência dessas colunas, o que confirma que o código foi escrito para conviver com ambientes inconsistentes. 

Além disso, a mensageria tem migration própria (018_messaging_outbox_and_history.sql), mas ela não aparece no log operacional de migrations aplicadas; em vez disso, o serviço cria tabelas com CREATE TABLE IF NOT EXISTS em runtime. 

Impacto

ambientes diferentes se comportam diferente com o mesmo código;

rollback e troubleshooting ficam imprevisíveis;

infraestrutura “se autoaltera” na primeira chamada de API;

difícil garantir homologação = produção.

Correção recomendada

eliminar DDL em runtime do MessagingDeliveryService;

aplicar/registrar formalmente a 018;

materializar no baseline as colunas financeiras faltantes da 006;

adotar uma tabela real de controle (schema_migrations) em vez de só migrations_applied.log. 

2) Banco de dados e migrations
2.1 Governança de schema ainda está incompleta
O próprio README define schema_current.sql como baseline oficial, mas admite pendências e ausência de schema_migrations. 

Isso é um sinal de que a trilha atual é semi-manual, dependente de disciplina operacional.

2.2 Tabela de estacionamento tem organizer_id, mas o fluxo não grava
O schema de parking_records possui coluna organizer_id. 
Mas o registerEntry() comenta que removeu organizer_id do insert “pois já está implícito no event_id” e realmente não persiste esse campo. 

Diagnóstico

funcionalmente o escopo está sendo garantido por JOIN events, então o controle atual funciona;

porém há inconsistência de modelagem: a coluna existe, mas não é fonte de verdade nem é mantida.

Risco

relatórios ou consultas futuras podem usar parking_records.organizer_id e encontrar NULL;

abre espaço para drift semântico e bugs silenciosos em BI/exportações.

Correção

escolher um modelo:

remover a coluna do schema, ou

popular e manter via trigger/insert/update.

2.3 offline_queue depende do evento, mas não carrega escopo explícito de tenant
A tabela offline_queue armazena event_id, device_id, payload_type, payload, offline_id, mas não armazena organizer_id. 

O controller compensa isso autorizando o evento contra o tenant do operador antes de processar. 

Diagnóstico

do jeito atual, funciona;

mas para auditoria posterior e análise forense, faltam colunas denormalizadas úteis (organizer_id, user_id, talvez sector).

Sugestão

incluir organizer_id e user_id em offline_queue;

isso reduz custo de investigação e melhora observabilidade.

3) Bugs silenciosos e inconsistências
3.1 Parte dos “bugs críticos” documentados já foi corrigida no código
O CLAUDE.md ainda lista como abertos:

AuthMiddleware sem name/email,

rota transfer órfã,

tickets sem filtro por organizer_id,

parking sem filtro por organizer_id,

bug de payload no audit. 

Mas o código atual já mostra:

AuthMiddleware retornando name e email; 

TicketController com rota POST /:id/transfer; 

TicketController filtrando por t.organizer_id; 

ParkingController protegendo por JOIN events e.organizer_id; 

AuditService aceitando id com fallback para sub. 

Conclusão
A documentação interna está defasada o suficiente para virar um risco operacional por si só.

Ação

tratar docs operacionais como item de release;

toda correção crítica deve atualizar CLAUDE.md e docs de diagnóstico no mesmo commit.

3.2 Login grava detalhes sensíveis demais no log
No fluxo de login há logs explícitos dizendo se o usuário existe, o id, role, is_active, e até o resultado do password_verify com tamanho do hash. 

Risco

exposição de metadados úteis para ataque;

vazamento operacional em logs centralizados;

quebra do princípio de mínimo detalhe em autenticação.

Correção

manter logging só em nível de auditoria e com redaction;

registrar motivo genérico e correlation id;

nunca logar resultado detalhado de comparação de senha.

3.3 Múltiplas sessões não são suportadas de forma saudável
No login, o sistema apaga todos os refresh tokens do usuário antes de emitir um novo. 
Ao mesmo tempo, os refresh tokens não têm session_id/device_id próprio no banco; a tabela guarda user_id, token_hash e expires_at. 

Impacto

login em um dispositivo derruba sessões em outros;

UX ruim para operação distribuída;

difícil auditar sessão por dispositivo.

Correção

modelar refresh tokens por sessão/dispositivo;

adicionar device_id, jti, revoked_at, last_used_at, ip, user_agent.

3.4 Teste de gateway não testa gateway de verdade
O testGatewayConnection() faz apenas validação de campos obrigatórios e retorna mode = validation_only, com a mensagem “sem transação real”. 

Diagnóstico

isso é bom como validação sintática;

mas não comprova conectividade, credencial válida, ambiente sandbox/prod, split ou webhook.

Risco

falso positivo operacional: “gateway conectado” sem conexão real.

Correção

criar testConnection real por provider:

Mercado Pago: consulta de credenciais/perfil;

Asaas: endpoint de account/me;

Pagar.me/PagSeguro idem;

separar “validação local” de “teste remoto”.

4) Integrações e consumidores
4.1 Mensageria
A stack de mensageria está relativamente bem estruturada:

configurações sensíveis são cifradas por SecretCryptoService; 

envios são persistidos em histórico/outbox; 

frontend usa os endpoints de bulk/manual. 

Pontos fracos

webhook sem assinatura;

schema criado em runtime;

wa_api_url é exposto no payload público de config, o que talvez não seja desejável externamente. 

4.2 E-mail/Resend
O serviço funciona, mas faz logs de debug com payload e resposta de erro do provedor. 

Sugestão

manter logs só com correlation id e status code;

mover corpo detalhado para observabilidade protegida.

4.3 IA / Gemini
O GeminiService chama diretamente a API do Google com prompt concatenado e billing posterior. 

Riscos

prompt gigante com payload bruto;

ausência de timeout/circuit breaker mais robusto;

acoplamento forte a um provider e a um caso de uso.

Melhoria

abstrair provider em interface única;

limitar tamanho de contexto;

registrar retries e latência por provider.

5) Arquitetura
5.1 Backend centralizado em um front controller + dispatch por arquivo
O backend/public/index.php concentra:

env loading,

CORS,

parsing de rota,

resposta JSON,

telemetry,

bootstrap global. 

Depois cada controller expõe uma função dispatch() procedural. Isso funciona, mas a arquitetura é fortemente acoplada, pouco modular e com baixa testabilidade.

Consequências

difícil isolar comportamento em testes;

fácil introduzir regressão em bootstrap global;

cada controller precisa lembrar manualmente de auth, escopo e respostas.

Evolução recomendada

migrar gradualmente para:

roteador explícito,

request/response objects,

middleware chain real,

services injetáveis,

contratos por módulo.

5.2 Frontend operacional, mas com bundle grande
O build passou, mas gerou um bundle JS principal de cerca de 1.69 MB minificado, com warning de chunk > 500 kB. Isso sugere monolito de rota/módulo no frontend.
Também há indício de base relativamente única, sem code splitting agressivo. 

Melhoria

lazy-load por página/módulo;

separar dashboard, participants, POS, AI, messaging em chunks;

medir LCP e TTI.

6) Qualidade, testes e confiabilidade
6.1 Não encontrei suíte real de testes automatizados
Na raiz prática do projeto, só aparecem scripts avulsos como backend/test_balance.php e test_meal_data.php, não uma suíte consistente de backend/frontend. Isso fragiliza correções futuras.

6.2 Lint do frontend está quebrado por problema de tooling
O script existe em package.json, mas a execução falhou por ENOENT envolvendo arquivo temporário vite.config.js.timestamp-...mjs. 

Leitura objetiva

isso não é bug de produto final;

é bug de pipeline/ferramental, mas afeta release safety.

7) Prioridade de correção sugerida
P0 — imediato
Assinar e autenticar webhook de mensageria.

Corrigir documentação de segurança para refletir o JWT real.

Parar bootstrap DDL em runtime na mensageria.

Revisar logs de autenticação e remover detalhes sensíveis.

P1 — próxima sprint
Formalizar controle de migrations com schema_migrations.

Fechar drift da 006_financial_hardening.

Decidir destino da coluna parking_records.organizer_id.

Implementar refresh token por sessão/dispositivo.

Criar teste remoto real para gateways.

P2 — estabilização
Introduzir testes de contrato da API.

Introduzir smoke tests autenticados multi-tenant.

Fazer code splitting do frontend.

Modularizar bootstrap/roteamento do backend.

8) O que está bom hoje
Nem tudo é problema. Alguns pontos estão melhores do que a documentação sugere:

Tickets já estão blindados por organizer_id. 

Estacionamento também já faz escopo por tenant via JOIN events. 

O middleware já entrega name/email, alinhando melhor auditoria e emissão. 

O frontend já saiu de localStorage para sessionStorage na sessão principal. 

O contexto de auth faz bootstrap com refresh silencioso. 

9) Conclusão prática
Minha leitura final é:

o sistema está funcional e já passou por hardening real, especialmente em multi-tenant, tickets, parking e sessão;

os maiores riscos não estão tanto na regra de negócio principal, mas em:

webhook/integridade de integração,

drift de schema/migration,

documentação desatualizada,

ausência de testes automatizados,

pipeline/tooling inconsistente.

Se você quiser, no próximo passo eu posso fazer uma dessas 3 coisas:

gerar um plano de ação executivo priorizado por impacto/esforço;

abrir uma auditoria técnica por arquivo/módulo, endpoint por endpoint;

corrigir os problemas críticos diretamente no código começando por webhook, migrations e auth logging.

Checks executados
✅ php -l backend/src/Helpers/JWT.php && php -l backend/src/Middleware/AuthMiddleware.php && php -l backend/src/Controllers/TicketController.php && php -l backend/src/Controllers/ParkingController.php && php -l backend/src/Controllers/ShopController.php && php -l backend/src/Controllers/MessagingController.php && php -l backend/src/Controllers/SyncController.php

✅ npm run build

⚠️ npm run lint (falhou por problema de tooling/arquivo temporário: vite.config.js.timestamp-...mjs)

✅ find . -maxdepth 3 \\( -path './node_modules' -o -path './frontend/node_modules' \\) -prune -o \\( -iname '*test*' -o -iname '*spec*' \\) -type f | sed 's#^./##' | sort | head -200