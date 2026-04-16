import { useState, useEffect } from 'react';
import { Users, Briefcase, CalendarCheck } from 'lucide-react';
import GuestManagementTab from './ParticipantsTabs/GuestManagementTab';
import WorkforceOpsTab from './ParticipantsTabs/WorkforceOpsTab';
import api from '../lib/api';
import { useEventScope } from '../context/EventScopeContext';

export default function ParticipantsHub() {
    const { eventId, setEventId } = useEventScope();
    const [activeTab, setActiveTab] = useState('guests');
    const [events, setEvents] = useState([]);

    useEffect(() => {
        api.get('/events').then(res => {
            const data = res.data.data || [];
            setEvents(data);
            if (!eventId && data.length > 0) setEventId(String(data[0].id));
        }).catch(() => {});
    }, [eventId, setEventId]);

    return (
        <div className="space-y-6 pb-12 animate-fade-in">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold font-headline text-slate-100 flex items-center gap-2">
                        <Users size={22} className="text-cyan-400" />
                        Participants Hub
                    </h1>
                    <p className="text-slate-400 text-sm mt-1">Gestão central unificada de Convidados e Equipe Operacional.</p>
                </div>
                
                <select
                    className="select bg-slate-800/50 border-slate-700/50 focus:border-cyan-500 rounded-xl min-w-[200px]"
                    value={eventId}
                    onChange={(e) => setEventId(e.target.value)}
                >
                    <option value="" disabled>Selecione um evento...</option>
                    {events.map(ev => (
                        <option key={ev.id} value={ev.id}>{ev.name}</option>
                    ))}
                </select>
            </div>

            <div className="flex gap-1 bg-slate-800/50 rounded-xl p-1 mt-6 w-fit">
                <button
                    onClick={() => setActiveTab('guests')}
                    className={`px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2 text-sm ${activeTab === 'guests' ? 'bg-cyan-500 text-slate-950' : 'text-slate-400 hover:text-slate-200'}`}
                >
                    <Users size={18} /> Guest Management
                </button>
                <button
                    onClick={() => setActiveTab('workforce')}
                    className={`px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2 text-sm ${activeTab === 'workforce' ? 'bg-cyan-500 text-slate-950' : 'text-slate-400 hover:text-slate-200'}`}
                >
                    <Briefcase size={18} /> Workforce Ops
                </button>
            </div>

            <div className="pt-4">
                {eventId ? (
                    <>
                        {activeTab === 'guests' && <GuestManagementTab eventId={eventId} />}
                        {activeTab === 'workforce' && <WorkforceOpsTab eventId={eventId} />}
                    </>
                ) : (
                    <div className="empty-state">
                        <CalendarCheck size={48} className="text-slate-700 mb-4" />
                        <h3 className="text-xl font-bold text-slate-100 mb-2">Selecione um Evento</h3>
                        <p className="text-slate-400 max-w-sm text-center">Para gerenciar os participantes ou equipe operativa, você precisa selecionar um evento ativo no topo da página.</p>
                    </div>
                )}
            </div>
        </div>
    );
}
