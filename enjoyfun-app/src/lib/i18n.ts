// Global i18n — detects device locale and returns canned strings.
// Keep this file tiny: only strings used before the first backend roundtrip.
// Anything user-facing after that should come from backend (localized via context.locale).

type Lang = 'pt' | 'en' | 'es';

function detect(): Lang {
  try {
    const raw = Intl.DateTimeFormat().resolvedOptions().locale ?? 'en';
    const prefix = raw.toLowerCase().slice(0, 2);
    if (prefix === 'pt') return 'pt';
    if (prefix === 'es') return 'es';
    return 'en';
  } catch {
    return 'en';
  }
}

export const currentLang: Lang = detect();
export const currentLocale: string = (() => {
  try {
    return Intl.DateTimeFormat().resolvedOptions().locale ?? 'en-US';
  } catch {
    return 'en-US';
  }
})();

const strings: Record<Lang, Record<string, string>> = {
  pt: {
    welcome_prompt:
      'Monte uma visao geral do evento de hoje: proximos horarios, lineup, mapa e principais alertas.',
    welcome_prompt_general:
      'Ola! Sou o assistente da plataforma EnjoyFun. Como posso te ajudar?',
    empty_title: 'Ola! Como posso ajudar?',
    empty_body:
      'Pergunte sobre vendas, staff, financeiro ou qualquer operacao do seu evento.',
    header_subtitle_touch: 'Toque para trocar de evento',
    header_subtitle_empty: 'Nenhum evento ativo',
    select_event: 'Selecionar evento',
    no_events: 'Nenhum evento disponivel.',
    input_placeholder: 'Pergunte qualquer coisa...',
    send: 'Enviar',
    settings: 'Ajustes',
    settings_soon: 'Em breve',
    mic_permission_denied: 'Permissao de microfone negada',
    transcribe_error: 'Falha ao transcrever audio',
    tts_on: 'Voz ligada',
    tts_off: 'Voz desligada',
    surface_general: 'Suporte ao Organizador',
    surface_dashboard: 'Painel do Evento',
    surface_parking: 'Estacionamento',
    surface_bar: 'Bar',
    surface_artists: 'Artistas',
    surface_workforce: 'Equipe',
    surface_finance: 'Financeiro',
    new_session: 'Nova conversa',
  },
  en: {
    welcome_prompt:
      'Give me an overview of today at this event: upcoming schedule, lineup, map and key alerts.',
    welcome_prompt_general:
      'Hi! I am the EnjoyFun platform assistant. How can I help you?',
    empty_title: 'Hi! How can I help?',
    empty_body:
      'Ask about sales, staff, finance or any operation of your event.',
    header_subtitle_touch: 'Tap to switch event',
    header_subtitle_empty: 'No active event',
    select_event: 'Select event',
    no_events: 'No events available.',
    input_placeholder: 'Ask anything...',
    send: 'Send',
    settings: 'Settings',
    settings_soon: 'Coming soon',
    mic_permission_denied: 'Microphone permission denied',
    transcribe_error: 'Failed to transcribe audio',
    tts_on: 'Voice on',
    tts_off: 'Voice off',
    surface_general: 'Organizer Support',
    surface_dashboard: 'Event Dashboard',
    surface_parking: 'Parking',
    surface_bar: 'Bar',
    surface_artists: 'Artists',
    surface_workforce: 'Workforce',
    surface_finance: 'Finance',
    new_session: 'New conversation',
  },
  es: {
    welcome_prompt:
      'Dame un resumen del evento de hoy: proximos horarios, lineup, mapa y alertas principales.',
    welcome_prompt_general:
      'Hola! Soy el asistente de la plataforma EnjoyFun. Como puedo ayudarte?',
    empty_title: 'Hola! Como puedo ayudarte?',
    empty_body:
      'Pregunta sobre ventas, staff, finanzas o cualquier operacion de tu evento.',
    header_subtitle_touch: 'Toca para cambiar de evento',
    header_subtitle_empty: 'Ningun evento activo',
    select_event: 'Seleccionar evento',
    no_events: 'No hay eventos disponibles.',
    input_placeholder: 'Pregunta lo que quieras...',
    send: 'Enviar',
    settings: 'Ajustes',
    settings_soon: 'Proximamente',
    mic_permission_denied: 'Permiso de microfono denegado',
    transcribe_error: 'Error al transcribir audio',
    tts_on: 'Voz activada',
    tts_off: 'Voz desactivada',
    surface_general: 'Soporte al Organizador',
    surface_dashboard: 'Panel del Evento',
    surface_parking: 'Estacionamiento',
    surface_bar: 'Bar',
    surface_artists: 'Artistas',
    surface_workforce: 'Equipo',
    surface_finance: 'Finanzas',
    new_session: 'Nueva conversacion',
  },
};

export function t(key: keyof (typeof strings)['pt']): string {
  return strings[currentLang][key] ?? strings.en[key] ?? String(key);
}
