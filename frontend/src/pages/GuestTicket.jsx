import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../lib/api';

export default function GuestTicket() {
  const [searchParams] = useSearchParams();
  const rawToken = searchParams.get('token');
  const token = (rawToken && rawToken !== 'undefined' && rawToken !== 'null') ? rawToken : '';

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [ticket, setTicket] = useState(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    api.get('/guests/ticket', { params: { token } })
      .then(({ data }) => {
        setTicket(data.data || null);
      })
      .catch((err) => {
        setError(err.response?.data?.message || 'Não foi possível carregar o convite.');
      })
      .finally(() => setLoading(false));
  }, [token]);

  const qrUrl = useMemo(
    () => `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(token)}`,
    [token],
  );

  if (!token) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
        <div className="w-full max-w-sm rounded-2xl border border-red-500/30 bg-red-500/10 p-5 text-center">
          <p className="text-red-300 font-semibold">Convite inválido</p>
          <p className="text-red-200/80 text-sm mt-2">Token do convite não informado ou corrompido na URL.</p>
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
          <p className="text-red-300 font-semibold">Convite inválido</p>
          <p className="text-red-200/80 text-sm mt-2">{error || 'Token do convite não informado.'}</p>
        </div>
      </div>
    );
  }

  const isCheckedIn = ticket.status === 'presente';

  return (
    <div className="min-h-screen bg-gray-950 px-4 py-6 flex items-start justify-center">
      <div className="w-full max-w-sm rounded-3xl border border-gray-800 bg-gradient-to-b from-gray-900 to-gray-950 shadow-2xl overflow-hidden">
        <div className="p-5 border-b border-gray-800">
          <p className="text-xs uppercase tracking-[0.25em] text-blue-300/80">Convite Digital</p>
          <h1 className="text-xl text-white font-bold mt-2 leading-tight">{ticket.event_name}</h1>
          <p className="text-sm text-gray-400 mt-1">{ticket.event_date || 'Data a confirmar'}</p>
        </div>

        <div className="px-5 pt-4">
          <p className="text-xs text-gray-500 uppercase tracking-[0.2em]">Convidado</p>
          <p className="text-lg font-semibold text-white mt-1">{ticket.guest_name}</p>
        </div>

        <div className="p-5">
          <div className="relative bg-white rounded-2xl p-4 flex flex-col items-center justify-center min-h-[290px] gap-3">
            <img src={qrUrl} alt="QR Code do convite" className="w-[250px] h-[250px] rounded-lg" />

            <div className="text-center w-full">
              <p className="font-mono font-bold text-gray-800 text-sm md:text-base tracking-wider break-all bg-gray-100 py-2 px-3 rounded-lg w-full border border-gray-200">
                REF: {token}
              </p>
            </div>

            {isCheckedIn && (
              <div className="absolute inset-0 bg-green-600/90 flex items-center justify-center rounded-2xl backdrop-blur-sm">
                <span className="text-white text-2xl font-black text-center px-3 leading-tight drop-shadow-md">CHECK-IN REALIZADO</span>
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
