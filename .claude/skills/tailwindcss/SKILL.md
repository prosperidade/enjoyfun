---
name: tailwindcss
description: >
  Padrões TailwindCSS para frontend EnjoyFun. Use ao estilizar componentes
  React, criar layouts, ou trabalhar com design system.
  Trigger: Tailwind, CSS, estilo, layout, design, cor, tema, responsivo.
---

# TailwindCSS — EnjoyFun

## Padrões
- Utility-first — sem CSS custom exceto quando impossível via Tailwind
- Responsivo: `sm:`, `md:`, `lg:` — mobile-first
- Dark mode: `dark:` prefix (quando implementado)
- Cores: usar palette definida no `tailwind.config.js`

## Classes Frequentes EnjoyFun
```jsx
// Card padrão
<div className="bg-white rounded-lg shadow-sm border p-4">

// Título de seção
<h2 className="text-lg font-semibold text-gray-900">

// Badge de status
<span className="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">

// Botão primário
<button className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">

// Grid de cards
<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

// Loading skeleton
<div className="animate-pulse bg-gray-200 rounded h-4 w-3/4">
```

## Proibido
- `style={{}}` inline
- CSS modules / styled-components
- `!important`
- Classes com valores arbitrários extensos (`w-[347px]`) — preferir design tokens
