Auditoria completa de meals
Abaixo está uma auditoria focada somente no módulo Meals, cobrindo banco de dados, fragilidades, bugs silenciosos, consumidores, integrações, consequências, soluções e cuidados operacionais.

1) Resumo executivo
O módulo de meals está bem mais protegido do que um fluxo CRUD comum: ele já possui validações de consistência no banco, idempotência para sync offline, trava transacional por participante/dia e validação forte da grade de serviços de refeição. Isso é um bom sinal. 

Mas o desenho atual ainda tem 4 fragilidades de alto valor de atenção:

Deriva de schema entre ambientes: o código muda de comportamento conforme tabela/coluna existe ou não. Isso evita crash, mas cria degradação silenciosa. 

Bug silencioso de timezone em consumed_at: timestamps ISO com offset (Z, -03:00, etc.) são aceitos, porém o offset é descartado na normalização. Isso pode jogar a refeição para dia/turno/serviço errados sem erro explícito. 

Histórico e saldo dependem do assignment atual: o saldo nasce do workforce_assignments em escopo, enquanto o histórico lê participant_meals. Se assignment/setor/turno mudar depois, o que foi consumido pode “sumir” do saldo ou mudar de visibilidade. 

Validações críticas da grade de refeições vivem mais na aplicação do que no banco: sobreposição de janelas, ordem operacional e coerência de sort_order são bem validadas no PHP, mas não estão blindadas no schema. Inserções manuais/migrações ruins podem quebrar a regra. 

2) Como o módulo meals está desenhado hoje
Endpoints principais
O controller expõe:

GET /meals/balance

GET /meals/services

GET /meals

POST /meals

POST /meals/standalone-qrs

POST /meals/external-qr

PUT /meals/services. 

Fluxo operacional de baixa
A baixa em POST /meals:

resolve participante por participant_id ou qr_token;

resolve automaticamente event_day_id e event_shift_id pelo consumed_at;

resolve o serviço de refeição por id/código/horário;

trava concorrência por participante+dia;

valida idempotência offline;

valida assignment elegível no recorte;

valida cota diária e unicidade por serviço;

persiste em participant_meals. 

Consumidor principal
A tela MealsControl.jsx:

registra refeição online via POST /meals;

enfileira refeição offline em offlineQueue;

sincroniza pela rota /sync;

usa o horário do dispositivo para compor consumed_at. 

Integrações laterais
O módulo também alimenta/projeta:

Financeiro do organizador, usando meal_unit_cost e/ou os serviços ativos do evento. 

Config financeira global, com fallback quando a coluna meal_unit_cost não existe. 

Proteção do calendário operacional do evento, impedindo mexer em dia/turno quando já há participant_meals. 

Sync offline, que reaproveita o mesmo domínio MealsDomainService. 

3) Pontos fortes já existentes
3.1 Blindagem no banco
O schema atual protege bem o núcleo:

participant_meals.event_day_id é obrigatório;

unit_cost_applied não pode ser negativo;

trigger valida coerência entre event_day_id, event_shift_id e meal_service_id;

existe unicidade para offline_request_id;

existe unicidade por participant_id + event_day_id + meal_service_id. 

3.2 Domínio operacional forte
O domínio não faz uma baixa “cega”:

rejeita participante fora do recorte;

rejeita baseline ambíguo;

aplica trava por participante/dia;

aplica limite diário;

impede repetir o mesmo serviço no mesmo dia. 

3.3 Grade de serviços validada
A configuração dos serviços do evento já bloqueia:

sort_order duplicado;

horários faltando;

janelas iguais;

grade sem serviço ativo;

rank incoerente com regra de cota;

janelas sobrepostas. 

3.4 Ferramenta de auditoria já existe
Existe script CLI dedicado para auditar meals por:

recentes,

integridade,

fora do dia operacional,

resumo. 

4) Achados críticos e relevantes
Achado 1 — Deriva de schema gera comportamento diferente por ambiente
Severidade: Alta

Evidência
O módulo consulta tableExists / columnExists em vários pontos para decidir o que faz:

leitura de balance depende de tabelas e coluna meal_unit_cost; 

financeiro faz fallback se meal_unit_cost não existir; 

escrita em participant_meals só grava meal_service_id, unit_cost_applied e offline_request_id se as colunas existirem. 

Consequência
Isso evita quebra imediata, mas cria um problema pior: o mesmo fluxo funciona com semânticas diferentes em ambientes diferentes.

Exemplos reais:

em um ambiente sem meal_service_id, a baixa perde granularidade por serviço;

em um ambiente sem offline_request_id, a idempotência offline fica enfraquecida;

em um ambiente sem meal_unit_cost, o financeiro pode subestimar custo e seguir “funcionando”. 

Solução
Criar baseline mínimo obrigatório de schema para o módulo Meals.

Transformar parte dos fallbacks em fail-fast em produção para write-paths críticos.

Expor uma rota ou diagnóstico de schema version / meals readiness.

Adicionar validação de migração em CI/CD antes de deploy.

Cuidados
Não remova todos os fallbacks de uma vez em ambiente legado.

Primeiro meça quais ambientes ainda estão “degradados”.

Depois faça rollout por feature flag ou por bloqueio progressivo.

Achado 2 — consumed_at aceita timezone, mas descarta o offset
Severidade: Alta
Tipo: bug silencioso

Evidência
normalizeConsumedAt() aceita ISO 8601 com Z ou offset, mas devolve apenas YYYY-MM-DD HH:MM:SS, sem converter timezone. 

Além disso:

o dia operacional é resolvido com base nesse timestamp normalizado; 

o turno também; 

a UI monta consumed_at com o horário do dispositivo. 

Consequência
Se o dispositivo estiver em timezone diferente do evento/servidor:

a refeição pode cair no dia operacional errado;

pode cair no turno errado;

pode resolver o serviço errado;

o erro pode passar sem exception, parecendo um consumo legítimo.

Esse é o tipo clássico de bug que só aparece depois em auditoria de saldo.

Solução
Padronizar consumed_at em UTC real no payload.

Persistir também um timezone do evento ou resolver tudo baseado no timezone configurado do evento/organizador.

No backend, usar parsing real com DateTimeImmutable, respeitando offset.

Idealmente guardar:

consumed_at_utc

consumed_at_local

event_timezone

Cuidados
Migrar isso exige revisar:

sync offline,

resolução de dia,

resolução de turno,

seleção automática de serviço.

Faça testes em cenários de madrugada e em eventos que atravessam meia-noite.

Achado 3 — Saldo e histórico usam fontes de verdade diferentes
Severidade: Alta

Evidência
O GET /meals/balance nasce da malha de workforce_assignments em escopo e só depois agrega consumo por participante. 

Já o GET /meals lê diretamente participant_meals, mas o filtro por setor reaplica EXISTS em workforce_assignments. 

Consequência
Se depois da baixa alguém:

trocar setor,

alterar turno,

remover assignment,

reorganizar workforce,

o histórico pode continuar existindo em participant_meals, mas:

o saldo pode deixar de mostrar a pessoa;

o histórico filtrado por setor pode mudar retroativamente;

a operação perde confiança, porque o número “mudou sozinho”.

Isso é especialmente crítico em fechamento de evento e reconciliação.

Solução
Separar claramente:

fato histórico da baixa (participant_meals);

estado atual do workforce.

Sugestões concretas:

gravar snapshot operacional na baixa:

sector_snapshot

role_id_snapshot

role_name_snapshot

shift_id_snapshot

assignment_id_snapshot

fazer o histórico usar prioritariamente snapshots.

deixar o balance distinguir:

“consumo histórico”

“membros atualmente elegíveis”

Cuidados
Não troque tudo de uma vez no balance; senão a UI muda semanticamente.

Melhor introduzir campos novos e responder os dois modelos por um tempo.

Achado 4 — Integridade da grade de refeições depende mais do app do que do banco
Severidade: Média/Alta

Evidência
No schema de event_meal_services, o banco garante basicamente:

colunas,

tipos,

service_code permitido,

unicidade (event_id, service_code). 

Mas as regras fortes de:

sort_order coerente,

não sobreposição,

ao menos um ativo,

coerência de slot operacional,

estão no MealsDomainService. 

Consequência
Se alguém:

rodar SQL manual,

aplicar migration incompleta,

corrigir dado diretamente no banco,

pode inserir uma grade inválida sem bloqueio estrutural. O efeito pode ser:

seleção automática errada,

conflitos de serviço,

falhas em operação por horário.

Solução
Subir parte da integridade para o banco:

CHECK (starts_at IS NOT NULL AND ends_at IS NOT NULL) para serviços ativos;

CHECK (sort_order > 0);

índice/constraint adicional para padrões de ordem, se o modelo permitir;

job de auditoria contínua para detectar sobreposição.

Cuidados
Sobreposição temporal complexa é difícil de resolver só com constraint simples. Nesse caso:

manter validação no app,

mas complementar com auditoria SQL programada.

Achado 5 — Offline tem boa idempotência, mas validação local é rasa
Severidade: Média

Evidência
A fila offline local só invalida explicitamente registros sem event_id válido antes de sincronizar. 

O payload offline é enfileirado com pouca validação estrutural local. 

O sync backend reaproveita o domínio correto, o que é bom. 

Consequência
Na prática:

registros ruins entram na fila local;

o operador acha que “guardou”;

só depois, na sincronização, a falha aparece.

Isso não é corrupção de dado, mas é uma fragilidade operacional:

mais retrabalho,

mais fila “failed”,

menos previsibilidade em operação degradada.

Solução
Validar localmente antes de enfileirar:

qr_token presente,

event_id válido,

event_day_id resolvido,

meal_service_id/meal_service_code coerentes,

consumed_at válido.

Também recomendo:

classificar falhas como retryable vs definitive;

exibir motivo direto na UI;

permitir correção assistida do registro falho.

Cuidados
Não replique toda a lógica do backend no frontend.
Faça só uma validação mínima estrutural; a regra final deve continuar no backend.

Achado 6 — Risco de subestimação financeira por fallback silencioso
Severidade: Média

Evidência
O financeiro usa meal_unit_cost global quando disponível, mas também monta contexto a partir dos serviços ativos do evento. 

Se a coluna meal_unit_cost não existir, o serviço financeiro devolve meal_unit_cost = 0 com um flag de disponibilidade. 

Consequência
O risco aqui não é quebra, e sim subestimação silenciosa:

projeção de custo menor que o real,

falsa sensação de margem,

inconsistência entre financeiro global e custo por serviço do evento.

Solução
Tornar o custo de meal um requisito explícito para organizadores que usam projeção financeira.

Exibir no backoffice um status forte: “projeção incompleta”.

Se meal_unit_cost estiver indisponível e não houver custos por serviço, bloquear projeção consolidada.

Cuidados
Não confundir:

“operação pode seguir” com

“financeiro está confiável”.

Esses são estados diferentes e precisam aparecer separados na UX e nos relatórios.

Achado 7 — Histórico operacional é protegido contra mudança de calendário, mas não contra mudança de assignment
Severidade: Média

Evidência
O evento impede alterações de calendário operacional quando já existem participant_meals. 

Mas a visão de meals continua dependente do estado atual do workforce em alguns pontos. 

Consequência
Você protege bem o calendário, mas não protege totalmente a leitura histórica contra remodelagem do workforce.
Ou seja:

o fato histórico continua salvo,

mas a leitura operacional/histórica pode mudar de aparência.

Solução
Snapshot de assignment na baixa.

Auditoria de órfãos entre participant_meals e workforce_assignments.

Relatório “consumo fora do vínculo atual”.

Cuidados
Esse ponto é muito importante para pós-evento, disputa de custo e conferência com fornecedores.

5) Banco de dados — leitura objetiva
O que está bom
participant_meals tem colunas centrais corretas. 

existe trigger de consistência. 

existe unicidade offline e por serviço/dia. 

event_meal_services tem domínio controlado por service_code. 

O que ainda está frágil no banco
sem constraint estrutural para grade temporal;

sem snapshot histórico da condição operacional;

sem noção explícita de timezone;

muito comportamento depende do código detectar schema existente.

Minha recomendação de evolução do schema
Prioridade prática:

Adicionar colunas snapshot em participant_meals

assignment_id_snapshot

sector_snapshot

role_id_snapshot

role_name_snapshot

event_timezone_snapshot

Padronizar horário

consumed_at_utc timestamptz

opcionalmente manter consumed_at_local

Endurecer event_meal_services

sort_order > 0

horários obrigatórios para ativos

auditoria SQL de sobreposição

Adicionar metadados de auditoria

source_channel (online, offline_replay, manual)

device_id / operator_user_id

correlation_id

6) Consumidores e integrações
6.1 Frontend MealsControl
É o consumidor mais importante:

registra online;

salva offline;

sincroniza via /sync;

depende do relógio local do dispositivo. 

Cuidado principal: relógio do dispositivo e timezone.

6.2 Sync offline
O sync reaproveita o domínio oficial, o que é ótimo porque evita regra duplicada. 

Cuidado principal: melhorar validação local e classificação de erros.

6.3 Financeiro
O módulo de refeições impacta projeções de custo do organizador. 

Cuidado principal: projeção não é confiável quando custo global/serviços não estão completos.

6.4 Evento / calendário operacional
Há proteção para não mexer em calendário quando já houve consumo. 

Cuidado principal: isso protege calendário, não protege semântica histórica do workforce.

7) Soluções priorizadas
Prioridade 1 — imediatamente
Corrigir timezone de consumed_at.

Criar auditoria diária automática usando o script existente como base.

Expor health/status do Meals por ambiente:

schema mínimo,

colunas críticas,

serviços ativos,

meal cost configurado.

Prioridade 2 — curto prazo
Persistir snapshot operacional na baixa.

Melhorar validação da fila offline no frontend.

Criar relatório de divergência:

consumo sem assignment atual,

consumo fora do dia operacional,

serviços sem grade válida.

Prioridade 3 — endurecimento estrutural
Reduzir fallback silencioso por schema drift.

Levar mais regras para o banco quando viável.

Separar semântica de “histórico” e “elegibilidade atual” nas APIs.

8) Cuidados de operação / go-live
Antes de entrar em operação forte com meals, eu validaria:

event_days cobrindo exatamente a operação real;

event_shifts sem sobreposição;

event_meal_services com janelas sem conflito;

meal_unit_cost ou custo por serviço devidamente configurado;

assignments coerentes por setor/turno;

timezone do evento definido e respeitado;

auditoria de fora-do-dia operacional rodando regularmente. 

9) Conclusão prática
Se eu tivesse que resumir em uma frase:

O módulo Meals está funcionalmente forte, mas ainda vulnerável a inconsistências silenciosas causadas por timezone, deriva de schema e dependência do estado atual do workforce para ler fatos históricos.

Ou seja:

não é um módulo frágil por ausência de regra;

ele é um módulo frágil por semântica histórica e por ambiente.

Se você quiser, no próximo passo eu posso fazer uma segunda etapa da auditoria, bem mais objetiva, em formato de:

matriz de risco (alto/médio/baixo),

checklist SQL de produção,

plano técnico de correção por PR,

queries prontas para detectar inconsistências reais no banco.

Verificações e comandos usados
✅ rg -n --hidden --glob '!**/node_modules/**' --glob '!**/.git/**' "\\bmeal(s)?\\b|Meal[A-Z_]|MEAL" .

✅ find backend -maxdepth 3 -type f | sed 's#^./##' | sort | rg 'MealController|MealsDomainService|ScannerController|WorkforceController|OrganizerFinanceController|FinancialSettingsService|routes|index.php|router|schema|sql|audit_meals|offlineQueue|db.js|MealsControl\\.jsx'

✅ php -l backend/src/Controllers/MealController.php && php -l backend/src/Services/MealsDomainService.php && php -l backend/src/Controllers/SyncController.php

✅ php backend/scripts/audit_meals.php help

✅ nl -ba backend/src/Controllers/MealController.php | sed -n '1,240p'

✅ nl -ba backend/src/Services/MealsDomainService.php | sed -n '720,860p'

✅ nl -ba database/schema_current.sql | sed -n '88,145p'

✅ nl -ba frontend/src/pages/MealsControl.jsx | sed -n '1117,1203p'

Observação: não fiz alterações no código nesta rodada; portanto não houve commit nem PR.