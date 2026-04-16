import React, { useState } from 'react';
import { Palette, MessageCircle, CreditCard, Bot, Crown } from 'lucide-react';
import BrandingTab from './SettingsTabs/BrandingTab';
import ChannelsTab from './SettingsTabs/ChannelsTab';
import FinanceTab from './SettingsTabs/FinanceTab';
import AIConfigTab from './SettingsTabs/AIConfigTab';
import PlanTab from './SettingsTabs/PlanTab';

export default function Settings() {
    const [activeTab, setActiveTab] = useState('branding');

    const tabs = [
        { id: 'branding', label: 'Identidade Visual', icon: <Palette size={18} /> },
        { id: 'channels', label: 'Canais de Contato', icon: <MessageCircle size={18} /> },
        { id: 'finance', label: 'Camada Financeira', icon: <CreditCard size={18} /> },
        { id: 'ai', label: 'Inteligência Artificial', icon: <Bot size={18} /> },
        { id: 'plan', label: 'Meu Plano', icon: <Crown size={18} /> },
    ];

    const renderTabContent = () => {
        switch (activeTab) {
            case 'branding': return <BrandingTab />;
            case 'channels': return <ChannelsTab />;
            case 'finance': return <FinanceTab />;
            case 'ai': return <AIConfigTab />;
            case 'plan': return <PlanTab />;
            default: return <BrandingTab />;
        }
    };

    return (
        <div className="p-6 max-w-6xl mx-auto space-y-6 animate-fade-in">
            <div className="mb-8">
                <h1 className="text-2xl font-bold font-headline text-slate-100">Configurações do Organizador</h1>
                <p className="text-slate-400 text-sm mt-1">Gerencie branding, canais e camada financeira do seu evento white-label.</p>
            </div>

            {/* Tabs Navigation */}
            <div className="flex overflow-x-auto space-x-1 border-b border-slate-800/40 pb-px mb-6 hide-scrollbar">
                {tabs.map((tab) => (
                    <button
                        key={tab.id}
                        onClick={() => setActiveTab(tab.id)}
                        className={`
                            flex items-center gap-2 px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-all duration-300
                            ${activeTab === tab.id
                                ? 'border-cyan-400 text-cyan-400'
                                : 'border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600'}
                        `}
                    >
                        {tab.icon}
                        {tab.label}
                    </button>
                ))}
            </div>

            {/* Tab Content Area */}
            <div className="transition-all duration-300">
                {renderTabContent()}
            </div>
        </div>
    );
}
