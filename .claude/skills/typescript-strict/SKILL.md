---
name: typescript-strict
description: >
  TypeScript strict para o app mobile EnjoyFun (React Native + Expo).
  Use ao escrever código TypeScript no projeto mobile.
  Trigger: TypeScript, TS, types, interface, mobile TypeScript.
---

# TypeScript Strict — EnjoyFun Mobile

## tsconfig
```json
{
  "compilerOptions": {
    "strict": true,
    "noImplicitAny": true,
    "strictNullChecks": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true
  }
}
```

## Padrões
- Interfaces para props e API responses (não `type` aliases para objetos)
- `unknown` em vez de `any` para dados externos
- Enums: usar `as const` objects em vez de `enum`
- Assertions: evitar `as Type` — preferir type guards

```typescript
// ✅ Correto
interface EventCardProps {
  eventId: number;
  title: string;
  date: string;
  onPress: (id: number) => void;
}

// ✅ Type guard
function isApiError(err: unknown): err is { message: string; code: number } {
  return typeof err === 'object' && err !== null && 'message' in err;
}

// ❌ Proibido
const data = response as any;
```
