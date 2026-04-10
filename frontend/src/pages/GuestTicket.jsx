import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { QRCodeSVG } from 'qrcode.react';
import publicApi from '../lib/publicApi';

export default function GuestTicket() {
  const [searchParams] = useSearchParams();
  const rawToken = searchParams.get('token');
  const token = (rawToken && rawToken !== 'undefined' && rawToken !== 'null') ? rawToken : '';

  const [loading, setLoading] = useState(() => Boolean(token));
  const [error, setError] = useState('');
  const [ticket, setTicket] = useState(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    publicApi.get('/guests/ticket', { params: { token } })
      .then(({ data }) => {
        setTicket(data.data || null);
      })
      .catch((err) => {
        setError(err.response?.data?.message || 'Não foi possível carregar o convite.');
      })
      .finally(() => setLoading(false));
  }, [token]);

  const displayToken = ticket?.qr_token || token;

  const normalizedStatus = String(ticket?.status || '').toLowerCase();
  const isCheckedIn = ['presente', 'present', 'checked-in', 'checked_in', 'utilizado', 'used'].includes(normalizedStatus);
  const isWorkforce = ticket?.source === 'workforce';
  const holderName = ticket?.holder_name || ticket?.guest_name || 'Participante';
  const title = isWorkforce ? 'Credencial Operacional' : 'Convite Digital';
  const audienceLabel = ticket?.audience_label || (isWorkforce ? 'Equipe' : 'Convidado');
  const settingsSourceLabel =
    ticket?.settings_source === 'member_override'
      ? 'configuração individual'
      : ticket?.settings_source === 'event_role'
        ? 'configuração do evento'
        : ticket?.settings_source === 'role_settings'
          ? 'configuração do cargo'
          : 'padrão operacional';

  if (!token) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
        <div className="w-full max-w-sm rounded-2xl border border-red-500/30 bg-red-500/10 p-5 text-center">
          <p className="text-red-300 font-semibold">Credencial inválida</p>
          <p className="text-red-200/80 text-sm mt-2">Token não informado ou corrompido na URL.</p>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
        <div className="text-center">
          <div className="spinner w-10 h-10 mx-auto mb-3" />
          <p className="text-gray-400 text-sm">Carregando convite...</p>
        </div>
      </div>
    );
  }

  if (error || !ticket) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
        <div className="w-full max-w-sm rounded-2xl border border-red-500/30 bg-red-500/10 p-5 text-center">
          <p className="text-red-300 font-semibold">Credencial inválida</p>
          <p className="text-red-200/80 text-sm mt-2">{error || 'Token não informado.'}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-950 px-4 py-6 flex items-start justify-center">
      <div className="w-full max-w-sm rounded-3xl border border-gray-800 bg-gradient-to-b from-gray-900 to-gray-950 shadow-2xl overflow-hidden">
        <div className="p-5 border-b border-gray-800">
          <p className="text-xs uppercase tracking-[0.25em] text-blue-300/80">{title}</p>
          <h1 className="text-xl text-white font-bold mt-2 leading-tight">{ticket.event_name}</h1>
          <p className="text-sm text-gray-400 mt-1">{ticket.event_date || 'Data a confirmar'}</p>
        </div>

        <div className="px-5 pt-4">
          <p className="text-xs text-gray-500 uppercase tracking-[0.2em]">{audienceLabel}</p>
          <p className="text-lg font-semibold text-white mt-1">{holderName}</p>
          <p className="text-sm text-gray-400 mt-1">{ticket.role_name || ticket.ticket_type}</p>
        </div>

        {isWorkforce && (
          <div className="px-5 pt-4 grid grid-cols-2 gap-3 text-xs">
            <div className="rounded-2xl border border-gray-800 bg-gray-900/70 p-3">
              <p className="text-gray-500 uppercase tracking-[0.18em]">Setor</p>
              <p className="text-white font-semibold mt-1">{String(ticket.sector || 'geral').replace(/_/g, ' ')}</p>
            </div>
            <div className="rounded-2xl border border-gray-800 bg-gray-900/70 p-3">
              <p className="text-gray-500 uppercase tracking-[0.18em]">Turnos</p>
              <p className="text-white font-semibold mt-1">{ticket.max_shifts_event ?? 1}</p>
            </div>
            <div className="rounded-2xl border border-gray-800 bg-gray-900/70 p-3">
              <p className="text-gray-500 uppercase tracking-[0.18em]">Horas</p>
              <p className="text-white font-semibold mt-1">{ticket.shift_hours ?? 8}h</p>
            </div>
            <div className="rounded-2xl border border-gray-800 bg-gray-900/70 p-3">
              <p className="text-gray-500 uppercase tracking-[0.18em]">Refeições</p>
              <p className="text-white font-semibold mt-1">{ticket.meals_per_day ?? 4}/dia</p>
            </div>
          </div>
        )}

        {ticket.settings_source ? (
          <div className="px-5 pt-3">
            <p className="text-[11px] text-gray-500 uppercase tracking-[0.18em]">
              Regra aplicada: {settingsSourceLabel}
            </p>
          </div>
        ) : null}

        <div className="px-5 pt-4">
          <div className="inline-flex rounded-full border border-gray-800 bg-gray-900/70 px-3 py-1 text-[11px] uppercase tracking-[0.18em] text-gray-300">
            {isCheckedIn ? 'Validado' : 'Pronto para validação'}
          </div>
        </div>

        <div className="p-5">
          <div className="relative bg-white rounded-2xl p-4 flex flex-col items-center justify-center min-h-[290px] gap-3">
            <QRCodeSVG value={displayToken} size={250} level="H" marginSize={2} className="rounded-lg" />

            <div className="text-center w-full">
              <p className="font-mono font-bold text-gray-800 text-sm md:text-base tracking-wider break-all bg-gray-100 py-2 px-3 rounded-lg w-full border border-gray-200">
                REF: {displayToken}
              </p>
            </div>

            {isCheckedIn && (
              <div className="absolute inset-0 bg-green-600/90 flex items-center justify-center rounded-2xl backdrop-blur-sm">
                <span className="text-white text-2xl font-black text-center px-3 leading-tight drop-shadow-md">VALIDAÇÃO REALIZADA</span>
              </div>
            )}
          </div>
        </div>

        {ticket.logo_url ? (
          <div className="px-5 pb-5">
            <img src={ticket.logo_url} alt="Logo do evento" className="h-10 object-contain opacity-90 mx-auto" />
          </div>
        ) : null}
      </div>
    </div>
  );
}
