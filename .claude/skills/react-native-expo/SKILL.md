---
name: react-native-expo
description: >
  Padrões React Native + Expo para o app mobile EnjoyFun (enjoyfun-app/).
  Use ao trabalhar em código mobile, telas do app, navegação, offline sync.
  Trigger: mobile, app, React Native, Expo, enjoyfun-app, tela mobile.
---

# React Native + Expo — EnjoyFun App

## Stack Mobile
- Expo SDK (managed workflow)
- TypeScript strict
- WatermelonDB para sync offline
- React Navigation

## Padrões
- Arquivos: PascalCase (`HomeScreen.tsx`, `ArtistCard.tsx`)
- Tipos: interfaces para props, nunca `any`
- Navegação: type-safe com `RootStackParamList`
- Offline-first: toda operação de leitura funciona sem rede
- Writes offline: fila local (`offline_queue`) com sync quando reconectar

## Estrutura
```
enjoyfun-app/
├── src/
│   ├── screens/        # PascalCase
│   ├── components/     # PascalCase
│   ├── hooks/          # use{Nome}
│   ├── services/       # API calls
│   ├── store/          # estado global
│   ├── types/          # interfaces TS
│   └── utils/          # helpers
├── app.json
└── tsconfig.json
```

## Regras
- Token JWT armazenado em SecureStore (não AsyncStorage)
- Imagens: usar cache e lazy loading
- Permissões: solicitar just-in-time, nunca no startup
- Deep linking: registrar scheme `enjoyfun://`
- Push: Expo Notifications com token por device+organizer
