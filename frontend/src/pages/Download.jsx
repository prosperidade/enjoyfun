import { useEffect, useMemo, useState } from 'react';
import { Apple, Smartphone, Download as DownloadIcon, CheckCircle2, Sparkles, ArrowRight } from 'lucide-react';

function detectPlatform() {
  if (typeof navigator === 'undefined') return 'desktop';
  const ua = navigator.userAgent || '';
  if (/iPad|iPhone|iPod/.test(ua) && !window.MSStream) return 'ios';
  if (/android/i.test(ua)) return 'android';
  return 'desktop';
}

export default function Download() {
  const [platform, setPlatform] = useState('desktop');
  const [deferredPrompt, setDeferredPrompt] = useState(null);
  const [installed, setInstalled] = useState(false);

  useEffect(() => {
    setPlatform(detectPlatform());
  }, []);

  useEffect(() => {
    const handler = (e) => {
      e.preventDefault();
      setDeferredPrompt(e);
    };
    const installedHandler = () => setInstalled(true);
    window.addEventListener('beforeinstallprompt', handler);
    window.addEventListener('appinstalled', installedHandler);
    return () => {
      window.removeEventListener('beforeinstallprompt', handler);
      window.removeEventListener('appinstalled', installedHandler);
    };
  }, []);

  const pwaInstall = async () => {
    if (!deferredPrompt) {
      alert('Use o menu do seu navegador para instalar o EnjoyFun como app (Adicionar a tela inicial).');
      return;
    }
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    if (outcome === 'accepted') setInstalled(true);
    setDeferredPrompt(null);
  };

  const cards = useMemo(() => ([
    {
      id: 'ios',
      title: 'App Store',
      subtitle: 'iPhone e iPad',
      description: 'Baixe via TestFlight enquanto estamos em beta fechado.',
      icon: Apple,
      href: '#',
      ctaLabel: 'Abrir TestFlight',
      highlight: platform === 'ios',
    },
    {
      id: 'android',
      title: 'Google Play',
      subtitle: 'Android',
      description: 'Entre no programa de teste interno para instalar.',
      icon: Smartphone,
      href: '#',
      ctaLabel: 'Entrar no teste',
      highlight: platform === 'android',
    },
    {
      id: 'pwa',
      title: 'Instalar como App',
      subtitle: 'Web / Desktop',
      description: 'Funciona offline, igual um app nativo. Sem loja.',
      icon: DownloadIcon,
      onClick: pwaInstall,
      ctaLabel: installed ? 'Instalado' : 'Instalar agora',
      highlight: platform === 'desktop',
    },
  ]), [platform, deferredPrompt, installed]);

  return (
    <div className="min-h-screen bg-gradient-to-b from-[#0A0A0A] via-[#0F0F1A] to-[#1A1A2E] text-white">
      <div className="max-w-5xl mx-auto px-5 py-16 sm:py-24">
        <header className="text-center mb-14">
          <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-white/10 bg-white/5 backdrop-blur-md text-xs text-purple-300 mb-5">
            <Sparkles size={12} />
            <span>EnjoyFun AI-First</span>
          </div>
          <h1 className="text-4xl sm:text-5xl font-bold tracking-tight mb-4">
            Baixe o <span style={{ color: '#E94560' }}>EnjoyFun</span>
          </h1>
          <p className="text-gray-400 max-w-xl mx-auto text-base">
            Seu evento inteiro na palma da mao. Ingressos, cashless, bar, estacionamento e uma IA que conversa com voce.
          </p>
        </header>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-10">
          {cards.map((card) => {
            const Icon = card.icon;
            const Tag = card.onClick ? 'button' : 'a';
            const tagProps = card.onClick
              ? { type: 'button', onClick: card.onClick }
              : { href: card.href, target: '_blank', rel: 'noopener noreferrer' };
            return (
              <Tag
                key={card.id}
                {...tagProps}
                className={`group text-left rounded-2xl border backdrop-blur-md p-5 transition-all duration-200 hover:-translate-y-0.5 hover:border-white/30 ${
                  card.highlight
                    ? 'bg-white/10 border-white/25 shadow-2xl shadow-purple-900/20'
                    : 'bg-white/5 border-white/10'
                }`}
              >
                <div
                  className="w-11 h-11 rounded-xl flex items-center justify-center mb-4 border border-white/10"
                  style={{ backgroundColor: card.highlight ? '#E94560' : 'rgba(255,255,255,0.05)' }}
                >
                  <Icon size={20} className="text-white" />
                </div>
                <div className="text-[10px] uppercase tracking-wider text-gray-500 mb-1">{card.subtitle}</div>
                <div className="text-lg font-semibold text-white mb-1.5">{card.title}</div>
                <p className="text-xs text-gray-400 leading-relaxed mb-4">{card.description}</p>
                <div className="inline-flex items-center gap-1.5 text-xs font-medium text-purple-300 group-hover:text-purple-200">
                  {card.ctaLabel}
                  {card.id === 'pwa' && installed ? (
                    <CheckCircle2 size={13} className="text-emerald-400" />
                  ) : (
                    <ArrowRight size={13} />
                  )}
                </div>
              </Tag>
            );
          })}
        </div>

        <section className="rounded-2xl border border-white/10 bg-white/5 backdrop-blur-md p-6">
          <h2 className="text-sm font-semibold text-white mb-3">Por que baixar o app?</h2>
          <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs text-gray-300">
            {[
              'Ingressos no bolso, sempre offline',
              'Cashless: pague com um toque',
              'IA que responde ao organizador em tempo real',
              'Notificacoes instantaneas do evento',
            ].map((feat, i) => (
              <li key={i} className="flex items-center gap-2">
                <CheckCircle2 size={14} className="text-emerald-400 flex-shrink-0" />
                <span>{feat}</span>
              </li>
            ))}
          </ul>
        </section>

        <footer className="text-center text-[11px] text-gray-600 mt-10">
          Problema para instalar? Fale com o organizador ou envie um email para suporte@enjoyfun.app
        </footer>
      </div>
    </div>
  );
}
