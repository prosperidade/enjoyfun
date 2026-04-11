// Global i18n — detects browser locale and returns canned strings.
// Keep this file tiny: only strings used before the first backend roundtrip.
// Anything user-facing after that should come from backend (localized via context.locale).

function detect() {
  try {
    const raw = (navigator.language || navigator.userLanguage || 'en').toLowerCase();
    if (raw.startsWith('pt')) return 'pt';
    if (raw.startsWith('es')) return 'es';
    return 'en';
  } catch {
    return 'en';
  }
}

export const currentLang = detect();
export const currentLocale = (() => {
  try {
    return navigator.language || 'en-US';
  } catch {
    return 'en-US';
  }
})();

const strings = {
  pt: {
    welcome_prompt:
      'Monte uma visao geral do evento de hoje: proximos horarios, lineup, mapa e principais alertas.',
    chat_placeholder: 'Pergunte qualquer coisa...',
    chat_greeting: 'Ola! Como posso ajudar?',
    download_title: 'Baixe o EnjoyFun',
    install_pwa: 'Instalar como App',
  },
  en: {
    welcome_prompt:
      'Give me an overview of today at this event: upcoming schedule, lineup, map and key alerts.',
    chat_placeholder: 'Ask anything...',
    chat_greeting: 'Hi! How can I help?',
    download_title: 'Download EnjoyFun',
    install_pwa: 'Install as App',
  },
  es: {
    welcome_prompt:
      'Dame un resumen del evento de hoy: proximos horarios, lineup, mapa y alertas principales.',
    chat_placeholder: 'Pregunta lo que quieras...',
    chat_greeting: 'Hola! Como puedo ayudarte?',
    download_title: 'Descarga EnjoyFun',
    install_pwa: 'Instalar como App',
  },
};

export function t(key) {
  return strings[currentLang]?.[key] ?? strings.en[key] ?? String(key);
}
