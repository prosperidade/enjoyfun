# EnjoyFun App (Mobile)

App nativo iOS + Android do EnjoyFun. Chat AI-first com renderer de blocos adaptativos.

## Pre-requisitos

- Node.js 20+
- Expo CLI: `npm i -g expo`
- EAS CLI: `npm i -g eas-cli`
- iOS: Xcode 15+ (macOS)
- Android: Android Studio + emulador ou device fisico

## Rodar em dev

```bash
cd enjoyfun-app
npm install
EXPO_PUBLIC_API_URL=http://localhost:8080/api npx expo start
```

Abra no simulador iOS (`i`), emulador Android (`a`) ou Expo Go.

Para dispositivo fisico apontando para backend local, troque `localhost` pelo IP da sua maquina (ex: `http://192.168.0.10:8080/api`).

## Variaveis de ambiente

- `EXPO_PUBLIC_API_URL` — base URL do backend PHP (default `http://localhost:8080/api`)

## Build (EAS)

```bash
eas login
eas build:configure
eas build -p android --profile preview   # APK interno
eas build -p ios --profile preview        # TestFlight interno
eas build --profile production            # stores
```

## Estrutura

- `App.tsx` — entry + navigation
- `src/screens/` — telas (Login, Chat)
- `src/components/` — UI shared
- `src/components/blocks/` — renderers dos blocos adaptativos
- `src/api/` — cliente HTTP
- `src/lib/` — types, auth storage
- `src/theme.ts` — design tokens
