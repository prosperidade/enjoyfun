import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../lib/api';
import { CalendarDays, MapPin, Clock, ArrowLeft, Users, CheckCircle } from 'lucide-react';
import toast from 'react-hot-toast';

export default function EventDetails() {
  const { id } = useParams();
  const [event, setEvent] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get(`/events/${id}`)
      .then(r => setEvent(r.data.data))
      .catch(() => toast.error('Erro ao carregar detalhes do evento.'))
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) return <div className="flex items-center justify-center py-20"><div className="spinner w-10 h-10" /></div>;
  if (!event) return <div className="text-center py-20 text-gray-400">Evento não encontrado.</div>;

  const starts = new Date(event.starts_at).toLocaleString('pt-BR');
  const ends = new Date(event.ends_at).toLocaleString('pt-BR');

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <Link to="/events" className="btn-outline inline-flex mb-4">
        <ArrowLeft size={16} /> Voltar para Eventos
      </Link>

      <div className="card overflow-hidden p-0 border-purple-800/40">
        <div className="h-48 bg-gradient-to-r from-purple-900 to-indigo-900 relative">
           <div className="absolute bottom-4 left-6 flex items-center gap-3">
              <div className="bg-gray-900 p-3 rounded-lg border border-purple-500 shadow-xl">
                 <CalendarDays size={32} className="text-purple-400" />
              </div>
              <div>
                 <h1 className="text-3xl font-bold text-white shadow-sm">{event.name}</h1>
                 <span className="badge-green mt-2 inline-block">{event.status.toUpperCase()}</span>
              </div>
           </div>
        </div>

        <div className="p-6 grid sm:grid-cols-2 gap-6">
          <div className="space-y-4">
            <h3 className="section-title">Informações</h3>
            
            {event.venue_name && (
              <div className="flex items-start gap-3 text-gray-300">
                <MapPin className="text-purple-400 shrink-0" size={20} />
                <div>
                  <p className="font-semibold">{event.venue_name}</p>
                  <p className="text-sm text-gray-500">{event.address}</p>
                </div>
              </div>
            )}
            
            <div className="flex items-center gap-3 text-gray-300">
              <Clock className="text-purple-400 shrink-0" size={20} />
              <div>
                <p className="text-sm">Início: <span className="font-semibold text-white">{starts}</span></p>
                <p className="text-sm">Fim: <span className="font-semibold text-white">{ends}</span></p>
              </div>
            </div>

            {event.capacity && (
              <div className="flex items-center gap-3 text-gray-300">
                <Users className="text-purple-400 shrink-0" size={20} />
                <p>Capacidade: <span className="font-semibold text-white">{parseInt(event.capacity).toLocaleString()} pessoas</span></p>
              </div>
            )}
          </div>

          <div className="space-y-4">
             <h3 className="section-title">Descrição</h3>
             <p className="text-gray-400 text-sm leading-relaxed whitespace-pre-wrap">
               {event.description || 'Nenhuma descrição fornecida.'}
             </p>

             <div className="mt-6 pt-6 border-t border-gray-800">
                <div className="flex items-center gap-2 text-green-400 font-semibold mb-2">
                   <CheckCircle size={18} /> Sistema Integrado
                </div>
                <div className="flex flex-wrap gap-2">
                   <Link to="/bar" className="badge-blue cursor-pointer hover:bg-blue-800">POS Ativo</Link>
                   <Link to="/tickets" className="badge-purple cursor-pointer hover:bg-purple-800">Bilheteria Linkada</Link>
                </div>
             </div>
          </div>
        </div>
      </div>
    </div>
  );
}
