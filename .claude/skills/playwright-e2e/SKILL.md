---
name: playwright-e2e
description: >
  Testes E2E com Playwright para EnjoyFun. Use ao criar smoke tests,
  testes de fluxo completo, ou validação de UI.
  Trigger: E2E, Playwright, smoke test, teste de tela, fluxo completo, UI test.
---

# Playwright E2E — EnjoyFun

## Setup
```bash
npm init playwright@latest
```

## Template de Smoke
```typescript
import { test, expect } from '@playwright/test';

test.describe('EnjoyFun Smoke', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('login flow', async ({ page }) => {
    await page.fill('[data-testid="email"]', 'test@enjoyfun.com');
    await page.fill('[data-testid="password"]', 'test123');
    await page.click('[data-testid="login-btn"]');
    await expect(page).toHaveURL(/dashboard/);
  });

  test('dashboard loads KPIs', async ({ page }) => {
    // após login
    await expect(page.locator('[data-testid="kpi-revenue"]')).toBeVisible();
    await expect(page.locator('[data-testid="kpi-tickets"]')).toBeVisible();
  });

  test('AI chat responds', async ({ page }) => {
    await page.click('[data-testid="ai-chat-toggle"]');
    await page.fill('[data-testid="ai-input"]', 'Como estão as vendas?');
    await page.click('[data-testid="ai-send"]');
    await expect(page.locator('[data-testid="ai-response"]')).toBeVisible({ timeout: 15000 });
  });
});
```

## Regras
- `data-testid` para seletores (nunca classes CSS ou texto)
- Timeouts generosos para chamadas de IA (15s+)
- Screenshots em falha: `use: { screenshot: 'only-on-failure' }`
- Rodar antes de todo deploy: `npx playwright test`
