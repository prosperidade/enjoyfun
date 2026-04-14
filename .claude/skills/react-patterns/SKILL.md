---
name: react-patterns
description: >
  Padrões React para o frontend EnjoyFun (Vite + TailwindCSS + Recharts).
  Use ao criar componentes, páginas, hooks, ou refatorar frontend.
  Trigger: React, componente, JSX, frontend, dashboard, hook, página.
---

# React Patterns — EnjoyFun Frontend

## Estrutura de Componente
```jsx
import { useState, useEffect } from 'react';
import { useNetwork } from '../hooks/useNetwork';

export default function ComponentName({ eventId }) {
  const { isOnline } = useNetwork();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    loadData();
  }, [eventId]);

  const loadData = async () => {
    try {
      setLoading(true);
      const res = await fetch(`/api/resource/${eventId}`);
      setData(await res.json());
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (loading) return <LoadingSkeleton />;
  if (error) return <ErrorState message={error} onRetry={loadData} />;
  return <div className="p-4">...</div>;
}
```

## Regras EnjoyFun
- Componentes: PascalCase, um por arquivo
- Hooks: `use{Nome}`, retornam objeto `{ data, loading, error }`
- Estado global: Redux (já configurado) — usar para auth, tenant, branding
- Estado local: `useState` para UI ephemeral
- API calls: sempre com try/catch, loading state, error state
- Offline: verificar `useNetwork().isOnline` antes de writes
- Tailwind: classes utilitárias, sem CSS custom exceto quando inevitável
- Charts: Recharts para dashboards
- i18n: labels da UI em português, variáveis e código em inglês

## Proibido
- `document.querySelector` / DOM manipulation direta
- `localStorage` para dados sensíveis (token em sessionStorage via AuthContext)
- Inline styles (usar Tailwind)
- `any` em TypeScript (mobile usa TS strict)
