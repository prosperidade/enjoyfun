import { useEffect, useState, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import toast, { Toaster } from 'react-hot-toast';
import publicApi from '../lib/publicApi';

/* ────────────────────────────────────────────────────────────
   PublicInvitation — Pagina publica de convite RSVP
   Rota: /convite/:eventSlug/:guestToken
   Sem autenticacao, mobile-first, dark theme + purple accents
   ──────────────────────────────────────────────────────────── */

const MEAL_OPTIONS = [
  { value: 'carne', label: 'Carne', icon: '🥩' },
  { value: 'peixe', label: 'Peixe', icon: '🐟' },
  { value: 'vegetariano', label: 'Vegetariano', icon: '🥗' },
  { value: 'vegano', label: 'Vegano', icon: '🌱' },
  { value: 'infantil', label: 'Infantil', icon: '🧒' },
];

function formatDate(dateStr) {
  if (!dateStr) return '';
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('pt-BR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

function formatTime(timeStr) {
  if (!timeStr) return '';
  // handle HH:mm:ss or HH:mm
  const parts = timeStr.split(':');
  return `${parts[0]}:${parts[1]}`;
}

function formatDateTime(dateStr) {
  if (!dateStr) return '';
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('pt-BR', {
      day: 'numeric',
      month: 'short',
    }) + ' - ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  } catch {
    return dateStr;
  }
}

/* ── Loading Skeleton ────────────────────────────────────── */
function LoadingState() {
  return (
    <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
      <div className="w-full max-w-lg animate-pulse space-y-6">
        <div className="h-48 rounded-2xl bg-gray-800/60" />
        <div className="space-y-3 px-4">
          <div className="h-8 w-3/4 mx-auto rounded bg-gray-800/60" />
          <div className="h-4 w-1/2 mx-auto rounded bg-gray-800/40" />
          <div className="h-4 w-2/3 mx-auto rounded bg-gray-800/40" />
        </div>
        <div className="h-40 rounded-2xl bg-gray-800/40" />
        <div className="h-56 rounded-2xl bg-gray-800/40" />
      </div>
    </div>
  );
}

/* ── Error / Not Found ───────────────────────────────────── */
function NotFoundState({ message }) {
  return (
    <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
      <div className="w-full max-w-sm text-center space-y-4">
        <div className="w-20 h-20 mx-auto rounded-full bg-gray-800/60 flex items-center justify-center">
          <svg className="w-10 h-10 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 9v.906a2.25 2.25 0 01-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 001.183 1.981l6.478 3.488m8.839 2.51l-4.66-2.51m0 0l-1.023-.55a2.25 2.25 0 00-2.134 0l-1.022.55m0 0l-4.661 2.51" />
          </svg>
        </div>
        <h2 className="text-xl font-semibold text-gray-200">Convite nao encontrado</h2>
        <p className="text-gray-400 text-sm">{message || 'Este convite pode ter expirado ou o link esta incorreto.'}</p>
      </div>
    </div>
  );
}

/* ── Status Badge ────────────────────────────────────────── */
function StatusBadge({ status }) {
  if (status === 'confirmed') {
    return (
      <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-sm font-medium animate-fade-in">
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        Presenca confirmada!
      </div>
    );
  }
  if (status === 'declined') {
    return (
      <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-red-500/15 border border-red-500/30 text-red-400 text-sm font-medium animate-fade-in">
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        Presenca recusada
      </div>
    );
  }
  return null;
}

/* ── Main Component ──────────────────────────────────────── */
export default function PublicInvitation() {
  const { eventSlug, guestToken } = useParams();

  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [data, setData] = useState(null);

  // RSVP form state
  const [rsvpStatus, setRsvpStatus] = useState(null); // 'confirmed' | 'declined'
  const [companion, setCompanion] = useState('');
  const [mealChoice, setMealChoice] = useState('');
  const [dietaryRestrictions, setDietaryRestrictions] = useState('');
  const [submitting, setSubmitting] = useState(false);

  /* ── Fetch invitation data ─────────────────────────────── */
  useEffect(() => {
    if (!eventSlug || !guestToken) {
      setNotFound(true);
      setLoading(false);
      return;
    }

    publicApi.get(`/invitations/${eventSlug}/${guestToken}`)
      .then(({ data: res }) => {
        const d = res.data || res;
        setData(d);
        // Pre-fill form if already responded
        if (d.guest?.rsvp_status) {
          setRsvpStatus(d.guest.rsvp_status);
        }
        if (d.guest?.companion_name) {
          setCompanion(d.guest.companion_name);
        }
        if (d.guest?.meal_choice) {
          setMealChoice(d.guest.meal_choice);
        }
        if (d.guest?.dietary_restrictions) {
          setDietaryRestrictions(d.guest.dietary_restrictions);
        }
      })
      .catch((err) => {
        if (err.response?.status === 404) {
          setNotFound(true);
        } else {
          setErrorMsg(err.response?.data?.message || 'Erro ao carregar convite.');
        }
      })
      .finally(() => setLoading(false));
  }, [eventSlug, guestToken]);

  /* ── Submit RSVP ───────────────────────────────────────── */
  const handleSubmit = useCallback(async () => {
    if (!rsvpStatus) {
      toast.error('Selecione se voce estara presente ou nao.');
      return;
    }

    setSubmitting(true);
    try {
      await publicApi.post(`/invitations/${eventSlug}/${guestToken}/rsvp`, {
        rsvp_status: rsvpStatus,
        companion_name: companion.trim() || null,
        meal_choice: mealChoice || null,
        dietary_restrictions: dietaryRestrictions.trim() || null,
      });

      // Update local state
      setData((prev) => ({
        ...prev,
        guest: {
          ...prev?.guest,
          rsvp_status: rsvpStatus,
          companion_name: companion.trim() || null,
          meal_choice: mealChoice || null,
          dietary_restrictions: dietaryRestrictions.trim() || null,
        },
      }));

      toast.success(
        rsvpStatus === 'confirmed'
          ? 'Presenca confirmada com sucesso!'
          : 'Resposta registrada. Esperamos que mude de ideia!',
      );
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao enviar resposta. Tente novamente.');
    } finally {
      setSubmitting(false);
    }
  }, [eventSlug, guestToken, rsvpStatus, companion, mealChoice, dietaryRestrictions]);

  /* ── Render states ─────────────────────────────────────── */
  if (loading) return <LoadingState />;
  if (notFound || errorMsg) return <NotFoundState message={errorMsg} />;
  if (!data) return <NotFoundState />;

  const { event, guest, ceremony_moments, sub_events } = data;
  const hasResponded = guest?.rsvp_status === 'confirmed' || guest?.rsvp_status === 'declined';

  return (
    <div className="min-h-screen bg-gray-950 text-gray-100">
      <Toaster
        position="top-center"
        toastOptions={{
          style: { background: '#1f2937', color: '#f9fafb', border: '1px solid #374151' },
          success: { iconTheme: { primary: '#8b5cf6', secondary: '#fff' } },
        }}
      />

      <div className="max-w-lg mx-auto pb-12">
        {/* ── Banner ──────────────────────────────────────── */}
        <div className="relative w-full h-56 sm:h-64 overflow-hidden">
          {event?.banner_url ? (
            <img
              src={event.banner_url}
              alt={event.name}
              className="w-full h-full object-cover"
            />
          ) : (
            <div className="w-full h-full bg-gradient-to-br from-purple-900/80 via-gray-900 to-purple-800/60" />
          )}
          {/* Gradient overlay */}
          <div className="absolute inset-0 bg-gradient-to-t from-gray-950 via-gray-950/40 to-transparent" />

          {/* Event type badge */}
          {event?.event_type && (
            <div className="absolute top-4 left-4 px-3 py-1 rounded-full bg-purple-500/20 border border-purple-500/30 text-purple-300 text-xs font-medium backdrop-blur-sm">
              {event.event_type}
            </div>
          )}
        </div>

        {/* ── Event Info ──────────────────────────────────── */}
        <div className="px-5 -mt-8 relative z-10 space-y-4 animate-fade-in">
          <div className="text-center space-y-2">
            <h1 className="text-3xl sm:text-4xl font-serif font-bold text-white leading-tight tracking-tight">
              {event?.name || 'Evento'}
            </h1>
            {event?.description && (
              <p className="text-gray-400 text-sm leading-relaxed max-w-sm mx-auto">
                {event.description}
              </p>
            )}
          </div>

          {/* Date, time, venue */}
          <div className="flex flex-col items-center gap-2 text-sm text-gray-300">
            {(event?.venue_name || event?.city) && (
              <div className="flex items-center gap-2">
                <svg className="w-4 h-4 text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0115 0z" />
                </svg>
                <span>
                  {[event?.venue_name, event?.city, event?.state].filter(Boolean).join(' \u00B7 ')}
                </span>
              </div>
            )}
            {event?.start_date && (
              <div className="flex items-center gap-2">
                <svg className="w-4 h-4 text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
                <span>
                  {formatDate(event.start_date)}
                  {event.start_time && ` \u00B7 ${formatTime(event.start_time)}`}
                </span>
              </div>
            )}
            {event?.map_url && (
              <a
                href={event.map_url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-purple-400 hover:text-purple-300 transition-colors text-sm"
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" />
                </svg>
                Ver no Google Maps
              </a>
            )}
          </div>
        </div>

        {/* ── Divider ─────────────────────────────────────── */}
        <div className="mx-5 my-6 border-t border-gray-800/80" />

        {/* ── Guest Greeting ──────────────────────────────── */}
        <div className="px-5 animate-fade-in" style={{ animationDelay: '100ms' }}>
          <div className="rounded-2xl border border-gray-800/80 bg-gray-900/50 p-5 text-center space-y-2">
            <p className="text-lg text-gray-200">
              Ola, <span className="font-semibold text-white">{guest?.name || 'Convidado(a)'}</span>!
            </p>
            <p className="text-gray-400 text-sm">
              Voce foi convidado(a) para este evento especial.
            </p>
            {guest?.guest_side && (
              <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-purple-500/10 border border-purple-500/20 text-purple-300 text-xs font-medium mt-1">
                Lado: {guest.guest_side}
              </div>
            )}
            {guest?.table_name && (
              <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-gray-700/40 border border-gray-700/60 text-gray-300 text-xs font-medium mt-1 ml-2">
                Mesa: {guest.table_name}
              </div>
            )}
          </div>
        </div>

        {/* ── Status Badge (if already responded) ─────────── */}
        {hasResponded && (
          <div className="px-5 mt-4 text-center animate-fade-in" style={{ animationDelay: '150ms' }}>
            <StatusBadge status={guest.rsvp_status} />
            <p className="text-gray-500 text-xs mt-2">
              Voce pode atualizar sua resposta abaixo.
            </p>
          </div>
        )}

        {/* ── Divider ─────────────────────────────────────── */}
        <div className="mx-5 my-6 border-t border-gray-800/80" />

        {/* ── RSVP Form ───────────────────────────────────── */}
        <div className="px-5 animate-fade-in" style={{ animationDelay: '200ms' }}>
          <h2 className="text-lg font-semibold text-white mb-4 text-center tracking-wide uppercase">
            Confirmar Presenca
          </h2>

          {/* Confirm / Decline buttons */}
          <div className="grid grid-cols-2 gap-3 mb-5">
            <button
              type="button"
              onClick={() => setRsvpStatus('confirmed')}
              className={`
                relative flex items-center justify-center gap-2 px-4 py-3.5 rounded-xl text-sm font-semibold transition-all duration-200 border
                ${rsvpStatus === 'confirmed'
                  ? 'bg-purple-600 border-purple-500 text-white shadow-lg shadow-purple-500/25'
                  : 'bg-gray-800/60 border-gray-700/60 text-gray-300 hover:bg-gray-800 hover:border-gray-600'
                }
              `}
            >
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Estarei la!
            </button>
            <button
              type="button"
              onClick={() => setRsvpStatus('declined')}
              className={`
                relative flex items-center justify-center gap-2 px-4 py-3.5 rounded-xl text-sm font-semibold transition-all duration-200 border
                ${rsvpStatus === 'declined'
                  ? 'bg-red-600/80 border-red-500/80 text-white shadow-lg shadow-red-500/20'
                  : 'bg-gray-800/60 border-gray-700/60 text-gray-300 hover:bg-gray-800 hover:border-gray-600'
                }
              `}
            >
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Nao posso
            </button>
          </div>

          {/* Additional fields — show when "confirmed" */}
          {rsvpStatus === 'confirmed' && (
            <div className="space-y-4 animate-fade-in">
              {/* Companion */}
              <div>
                <label className="block text-sm font-medium text-gray-300 mb-1.5">
                  Nome do(a) acompanhante
                </label>
                <input
                  type="text"
                  value={companion}
                  onChange={(e) => setCompanion(e.target.value)}
                  placeholder="Deixe vazio se ira sozinho(a)"
                  className="w-full px-4 py-3 rounded-xl bg-gray-800/60 border border-gray-700/60 text-gray-100 placeholder-gray-500 text-sm focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition-colors"
                />
              </div>

              {/* Meal choice */}
              <div>
                <label className="block text-sm font-medium text-gray-300 mb-2">
                  Escolha do menu
                </label>
                <div className="flex flex-wrap gap-2">
                  {MEAL_OPTIONS.map((opt) => (
                    <button
                      key={opt.value}
                      type="button"
                      onClick={() => setMealChoice(opt.value === mealChoice ? '' : opt.value)}
                      className={`
                        inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full text-sm font-medium transition-all duration-200 border
                        ${mealChoice === opt.value
                          ? 'bg-purple-600/90 border-purple-500 text-white shadow-md shadow-purple-500/20'
                          : 'bg-gray-800/50 border-gray-700/50 text-gray-300 hover:bg-gray-800 hover:border-gray-600'
                        }
                      `}
                    >
                      <span>{opt.icon}</span>
                      {opt.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Dietary restrictions */}
              <div>
                <label className="block text-sm font-medium text-gray-300 mb-1.5">
                  Restricoes alimentares
                </label>
                <textarea
                  value={dietaryRestrictions}
                  onChange={(e) => setDietaryRestrictions(e.target.value)}
                  placeholder="Alergias, intolerancias ou preferencias especiais..."
                  rows={3}
                  className="w-full px-4 py-3 rounded-xl bg-gray-800/60 border border-gray-700/60 text-gray-100 placeholder-gray-500 text-sm focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition-colors resize-none"
                />
              </div>
            </div>
          )}

          {/* Submit button */}
          <button
            type="button"
            onClick={handleSubmit}
            disabled={submitting || !rsvpStatus}
            className={`
              w-full mt-5 flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl text-sm font-semibold transition-all duration-200
              ${!rsvpStatus || submitting
                ? 'bg-gray-800/60 text-gray-500 cursor-not-allowed border border-gray-700/40'
                : 'bg-purple-600 hover:bg-purple-500 text-white shadow-lg shadow-purple-500/25 border border-purple-500/80 active:scale-[0.98]'
              }
            `}
          >
            {submitting ? (
              <>
                <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
                Enviando...
              </>
            ) : (
              <>
                {hasResponded ? 'Atualizar Resposta' : 'Confirmar Presenca'}
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
              </>
            )}
          </button>
        </div>

        {/* ── Ceremony Moments ────────────────────────────── */}
        {ceremony_moments && ceremony_moments.length > 0 && (
          <>
            <div className="mx-5 my-6 border-t border-gray-800/80" />
            <div className="px-5 animate-fade-in" style={{ animationDelay: '300ms' }}>
              <h2 className="text-lg font-semibold text-white mb-4 text-center tracking-wide uppercase">
                Momentos do Dia
              </h2>
              <div className="relative">
                {/* Timeline line */}
                <div className="absolute left-[17px] top-2 bottom-2 w-px bg-gradient-to-b from-purple-500/60 via-purple-500/30 to-transparent" />

                <div className="space-y-4">
                  {ceremony_moments.map((moment, idx) => (
                    <div key={moment.id || idx} className="flex items-start gap-4 relative">
                      {/* Dot */}
                      <div className="relative z-10 w-[9px] h-[9px] mt-1.5 rounded-full bg-purple-500 ring-4 ring-gray-950 shrink-0 ml-[13px]" />
                      <div className="flex-1 min-w-0">
                        <div className="flex items-baseline gap-2">
                          {moment.scheduled_time && (
                            <span className="text-purple-400 text-sm font-semibold tabular-nums">
                              {formatTime(moment.scheduled_time)}
                            </span>
                          )}
                          <span className="text-gray-200 text-sm font-medium">
                            {moment.title || moment.name}
                          </span>
                        </div>
                        {moment.description && (
                          <p className="text-gray-500 text-xs mt-0.5">{moment.description}</p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </>
        )}

        {/* ── Sub-Events ──────────────────────────────────── */}
        {sub_events && sub_events.length > 0 && (
          <>
            <div className="mx-5 my-6 border-t border-gray-800/80" />
            <div className="px-5 animate-fade-in" style={{ animationDelay: '400ms' }}>
              <h2 className="text-lg font-semibold text-white mb-4 text-center tracking-wide uppercase">
                Eventos Relacionados
              </h2>
              <div className="space-y-3">
                {sub_events.map((se, idx) => (
                  <div
                    key={se.id || idx}
                    className="rounded-xl border border-gray-800/80 bg-gray-900/40 p-4 space-y-1.5"
                  >
                    <div className="flex items-center gap-2">
                      <svg className="w-4 h-4 text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                      </svg>
                      <span className="text-gray-100 text-sm font-semibold">
                        {se.name || se.title}
                      </span>
                    </div>
                    {(se.start_datetime || se.venue_name) && (
                      <p className="text-gray-500 text-xs pl-6">
                        {se.start_datetime && formatDateTime(se.start_datetime)}
                        {se.start_datetime && se.venue_name && ' \u00B7 '}
                        {se.venue_name}
                      </p>
                    )}
                    {se.description && (
                      <p className="text-gray-400 text-xs pl-6">{se.description}</p>
                    )}
                  </div>
                ))}
              </div>
            </div>
          </>
        )}

        {/* ── Footer ──────────────────────────────────────── */}
        <div className="mt-10 pb-6 text-center">
          <p className="text-gray-600 text-xs">
            Powered by <span className="font-semibold text-gray-500">EnjoyFun</span>
          </p>
        </div>
      </div>

      {/* ── Global animation styles ──────────────────────── */}
      <style>{`
        @keyframes fade-in {
          from { opacity: 0; transform: translateY(8px); }
          to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
          animation: fade-in 0.5s ease-out both;
        }
      `}</style>
    </div>
  );
}
