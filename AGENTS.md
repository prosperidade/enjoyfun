# AGENTS.md — EnjoyFun Participant

> Leia este arquivo **antes** de qualquer mudança. Ele descreve a arquitetura real do app — não a aparente.

---

## TL;DR para agentes

1. Este app é **chat-first**. A única tela pós-login é `ImmersiveChatScreen`.
2. Toda UI dinâmica vem do backend via `POST /ai/chat` em forma de **blocos adaptativos** (`blocks[]`).
3. **Não adicione novas telas.** Adicione novos **tipos de bloco** em `src/components/blocks/`.
4. **Não adicione react-navigation.** Foi removido de propósito.
5. Se uma tarefa parece exigir uma tela nativa nova (ex.: scanner de QR), adicione um tipo de bloco que abre um modal/sheet — não uma rota.

---

## Arquitetura

### Fluxo único do app

```
index.ts
  └── App.tsx
        ├── (não autenticado) LoginScreen ──► POST /auth/login
        └── (autenticado)     ImmersiveChatScreen ──► POST /ai/chat
                                    │
                                    └── AdaptiveBlockRenderer
                                          └── blocks/* (um arquivo por tipo)
```

Não há `NavigationContainer`. Não há `Stack.Navigator`. Não há abas.

### Por que chat-first

A tagline do produto é *"A era dos menus acabou"*. O participante do evento conversa com o concierge; o concierge devolve UI sob demanda em forma de blocos. Tudo o que um app tradicional resolveria com 10 telas, este resolve com 1 chat + N tipos de bloco.

### Contrato com o backend

Existe **um único endpoint** que importa pro front: `POST /ai/chat`.

**Request:**
```json
{
  "message": "qual o line-up?",
  "event_id": 1,
  "conversation_id": "uuid-v4-gerado-no-cliente",
  "surface": "b2c",
  "conversation_mode": "embedded",
  "locale": "pt-BR",
  "context": { ... }            // opcional
}
```

**Response (envelopada em `data`):**
```json
{
  "data": {
    "text_fallback": "string opcional",
    "blocks": [
      { "id": "...", "type": "insight",  ... },
      { "id": "...", "type": "lineup",   ... },
      { "id": "...", "type": "actions",  ... }
    ]
  }
}
```

`/auth/login` é o único outro endpoint usado. **Qualquer outro endpoint REST é responsabilidade do backend** — o front nunca chama `/b2c/*` diretamente. Se você sentir vontade de adicionar `getX()` em `src/api/`, pare. Faça o backend devolver isso como bloco.

---

## Estrutura de pastas (target)

```
src/
├── api/
│   ├── client.ts          # axios + interceptors (auth token, 401 handler)
│   └── auth.ts            # login() apenas
├── components/
│   ├── GlassCard.tsx      # primitivo visual reutilizável
│   └── blocks/            # ← AQUI você adiciona tipos de bloco
│       ├── index.tsx      # roteador (BlockRouter)
│       ├── types.ts       # discriminated union de Block
│       ├── Insight.tsx
│       ├── Text.tsx
│       ├── CardGrid.tsx
│       ├── Actions.tsx
│       ├── Timeline.tsx
│       ├── Lineup.tsx
│       ├── Map.tsx
│       ├── Image.tsx
│       ├── Table.tsx
│       ├── Stages.tsx
│       ├── Sectors.tsx
│       └── Sessions.tsx
├── lib/
│   ├── auth.ts            # SecureStore wrappers (token, user)
│   └── theme.ts           # design system "Aether Neon"
└── screens/
    ├── LoginScreen.tsx
    └── ImmersiveChatScreen.tsx
```

Não crie outras pastas em `screens/`. Não recrie `context/EventContext.tsx`, `navigation/`, `api/b2c.ts` — foram removidos de propósito (eram da arquitetura B descontinuada).

---

## Como adicionar um novo tipo de bloco

Cenário típico: o backend agora devolve um bloco novo, ex. `qr_ticket`.

1. Defina o shape em `src/components/blocks/types.ts`:
   ```ts
   export interface QrTicketBlock {
     id: string;
     type: 'qr_ticket';
     ticket_id: string;
     holder_name: string;
     payload: string;        // string que vai virar QR
     sector?: string;
   }
   ```
   E adicione na union `Block`.

2. Crie `src/components/blocks/QrTicket.tsx` exportando um componente React que renderiza o bloco. Use `GlassCard` e tokens do `theme.ts`.

3. Registre no roteador `src/components/blocks/index.tsx`:
   ```tsx
   case 'qr_ticket': return <QrTicket block={block} />;
   ```

4. Se o bloco precisa abrir UI fullscreen (modal, sheet), faça-o **dentro do componente do bloco** com `Modal` do RN ou `react-native-bottom-sheet`. Não promova a tela.

5. Se o bloco tem ações, dispare `onAction({ intent, params })`, **não** `sendMessage(label)`. O `ImmersiveChatScreen` lida com ambos hoje, mas intent estruturada é o caminho.

Pronto. Sem rotas, sem context, sem nada novo.

---

## Coisas que NÃO fazer

- ❌ **Não adicionar telas.** Mesmo que pareça mais simples. Vai virar arquitetura B de novo.
- ❌ **Não reintroduzir `@react-navigation/*`.** Foi removido. Bundle agradece.
- ❌ **Não criar `EventContext` nem nenhum context global de dados.** Estado de evento vive no backend. O front sabe `event_id` (vindo do `user`) e `conversation_id` (gerado no boot). Mais nada.
- ❌ **Não chamar endpoints REST além de `/auth/login` e `/ai/chat`.** Se precisar de dado, peça pro backend devolver como bloco.
- ❌ **Não usar `any` em código novo.** Tipar `Block` como union discriminada não é decoração — é o contrato com o backend.
- ❌ **Não adicionar `console.log` fora de `if (__DEV__)`.**
- ❌ **Não hardcodar URLs, IPs, IDs de evento.** Veja seção "Configuração" abaixo.

---

## Configuração

- **API URL:** lida via `Constants.expoConfig?.extra?.EXPO_PUBLIC_API_URL`. Em produção use `https://`. Em dev pode ser IP de LAN, mas configurado via `app.config.ts` por ambiente, **não fixo no `app.json`**.
- **`event_id`:** vem de `getUser()` (campo `event_id` ou `primary_event_id` do user persistido no login). Se não vier, mostre erro amigável; **não** caia em `1` silenciosamente.
- **`conversation_id`:** gerar uma vez no `useEffect` inicial do `ImmersiveChatScreen` com UUID v4 e enviar em todos os `POST /ai/chat`. Persistir em `SecureStore` se quiser sobreviver reload, mas não obrigatório.

---

## Estilo & design system

- Tokens em `src/lib/theme.ts`. **Nunca hardcode cores ou spacing.** Use `colors.primary`, `spacing.md`, etc.
- Fontes: Space Grotesk (display/headings/labels) e Manrope (body). Carregadas via `useFonts()` no `App.tsx`. Se uma fonte não estiver carregada, o app não renderiza (splash continua).
- `GlassCard` é o primitivo visual. Em iOS usa `BlurView`; em Android cai num fundo translúcido. Aceitável — não tente "consertar" com lib nativa de blur sem discutir.
- Estética é "Aether Neon" / brutalist: bordas neon, glows, tipografia pesada, monoespaçada para tempos. Não introduza Material Design padrão, sombras suaves cinzas, etc.

---

## Tratamento de erro no chat

Quando `POST /ai/chat` falha, **não** mostre "Desculpe, tive um problema" genérico. Inspecione `err.response?.status`:

- `401` → o interceptor já desloga; mostre balão "Sessão expirada, faça login novamente".
- `429` → "Muitas perguntas seguidas, aguarde um momento."
- `5xx` → "Nossos servidores tiveram um problema. [Tentar novamente]"
- `timeout` ou sem `response` → "Sem conexão. Verifique sua internet. [Tentar novamente]"

O balão de erro **deve ter botão de retry** que reenvia a última mensagem.

---

## Performance

- A `FlatList` do chat ainda não é `inverted`. Se você for mexer em scroll, considere migrar para `inverted` + lista reversa (padrão de chat RN). Aí `scrollToEnd` e `setTimeout(200)` saem.
- Não adicione re-renders no topo do `ImmersiveChatScreen`. Memoize `renderMessage` se for crescer.
- Cada bloco deve ser um componente próprio para isolar re-render.

---

## Tipagem

`tsconfig.json` tem `strict: true`. Use. `npm run typecheck` (`tsc --noEmit`) deve passar limpo antes de qualquer commit.

```ts
// ❌ ruim
function ActionsBlock({ block, onAction }: { block: any; onAction?: (a: any) => void })

// ✅ bom
import type { ActionsBlock as ActionsBlockType, BlockAction } from './types';
function ActionsBlock({ block, onAction }: { block: ActionsBlockType; onAction?: (a: BlockAction) => void })
```

---

## Testes

Mínimo desejável: snapshot por tipo de bloco em `src/components/blocks/__tests__/`. Se você adicionar um bloco novo, adicione o snapshot junto. Roda em CI.

---

## Quando este documento estiver desatualizado

Se você está prestes a fazer algo que este documento proíbe e tem boa razão, **atualize este documento na mesma PR**. Documento mentir é pior do que não existir.

---

## Histórico

- **v1.0** — App nasceu como arquitetura tradicional com 11 telas + react-navigation + EventContext + REST `/b2c/*`. Pivot incompleto deixou esse código convivendo com chat-first.
- **v2.0 (atual)** — Pivot consolidado: chat-first, 1 tela pós-login, blocos adaptativos. Código da v1 removido. Este documento criado para impedir regressão.
