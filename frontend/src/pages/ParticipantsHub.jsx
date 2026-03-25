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
                    <h1 className="page-title flex items-center gap-2">
                        <Users size={22} className="text-brand" />
                        Participants Hub
                    </h1>
                    <p className="text-gray-400 text-sm mt-1">Gestão central unificada de Convidados e Equipe Operacional.</p>
                </div>
                
                <select 
                    className="select bg-gray-900 border-gray-700 min-w-[200px]"
                    value={eventId}
                    onChange={(e) => setEventId(e.target.value)}
                >
                    <option value="" disabled>Selecione um evento...</option>
                    {events.map(ev => (
                        <option key={ev.id} value={ev.id}>{ev.name}</option>
                    ))}
                </select>
            </div>

            <div className="flex gap-4 border-b border-gray-800 pb-px mt-6">
                <button
                    onClick={() => setActiveTab('guests')}
                    className={`pb-3 px-2 border-b-2 font-medium transition-colors flex items-center gap-2 ${activeTab === 'guests' ? 'border-brand text-brand' : 'border-transparent text-gray-400 hover:text-gray-200'}`}
                >
                    <Users size={18} /> Guest Management
                </button>
                <button
                    onClick={() => setActiveTab('workforce')}
                    className={`pb-3 px-2 border-b-2 font-medium transition-colors flex items-center gap-2 ${activeTab === 'workforce' ? 'border-brand text-brand' : 'border-transparent text-gray-400 hover:text-gray-200'}`}
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
                        <CalendarCheck size={48} className="text-gray-700 mb-4" />
                        <h3 className="text-xl font-bold text-white mb-2">Selecione um Evento</h3>
                        <p className="text-gray-400 max-w-sm text-center">Para gerenciar os participantes ou equipe operativa, você precisa selecionar um evento ativo no topo da página.</p>
                    </div>
                )}
            </div>
        </div>
    );
}
