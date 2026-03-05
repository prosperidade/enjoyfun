import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { QRCodeSVG } from 'qrcode.react';
import { ArrowLeft, Zap, CheckCircle, Copy, Loader2, Globe, Building2 } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';

const PRESET_VALUES = [10, 25, 50, 100];
const MOCK_ORGANIZER_ID = 1; // TODO: resolver via slug

export default function CustomerRecharge() {
  const { slug }   = useParams();
  const navigate   = useNavigate();

  const [amount, setAmount]         = useState('');
  const [rechargeType, setRechargeType] = useState('global'); // 'global' | 'event'
  const [loading, setLoading]       = useState(false);
  const [pixResult, setPixResult]   = useState(null);
  const [copied, setCopied]         = useState(false);

  const handleGeneratePix = async (e) => {
    e.preventDefault();
    const value = parseFloat(amount);
    if (!value || value < 1) return toast.error('Informe um valor mínimo de R$ 1,00.');

    setLoading(true);
    try {
      const payload = {
        amount: value,
        recharge_type: rechargeType,
        organizer_id: rechargeType === 'event' ? MOCK_ORGANIZER_ID : 0,
      };
      const { data } = await api.post('/customer/recharge', payload);
      setPixResult(data.data);
      toast.success('QR Code Pix gerado! Escaneie para pagar.');
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erro ao gerar Pix. Tente novamente.');
    } finally {
      setLoading(false);
    }
  };

  const handleCopy = () => {
    if (!pixResult?.pix_code) return;
    navigator.clipboard.writeText(pixResult.pix_code).then(() => {
      setCopied(true);
      toast.success('Código Pix copiado! ✅');
      setTimeout(() => setCopied(false), 3000);
    });
  };

  const formatCurrency = (v) =>
    parseFloat(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

  return (
    <div
      className="min-h-screen bg-gray-950 flex flex-col"
      style={{ background: 'radial-gradient(ellipse at top, rgba(124,58,237,0.12) 0%, #030712 60%)' }}
    >
      <div className="flex-1 flex flex-col max-w-md mx-auto w-full px-4 py-6 space-y-5">

        {/* Header */}
        <div className="flex items-center gap-3">
          <button
            onClick={() => navigate(`/app/${slug}/home`)}
            className="w-9 h-9 rounded-xl border border-gray-800 flex items-center justify-center text-gray-400 hover:text-white hover:border-gray-600 transition-all"
          >
            <ArrowLeft size={18} />
          </button>
          <div>
            <h1 className="text-lg font-bold text-white">Carregar Saldo</h1>
            <p className="text-xs text-gray-500">Recarga via Pix — crédito imediato</p>
          </div>
        </div>

        {!pixResult ? (
          /* ── Step 1: Formulário de valor ─────────────────────── */
          <form onSubmit={handleGeneratePix} className="space-y-5">

            {/* Recharge type toggle */}
            <div>
              <p className="text-xs text-gray-500 uppercase tracking-widest mb-2">Carregar para</p>
              <div className="grid grid-cols-2 gap-2">
                {[
                  { id: 'global', label: 'Carteira EnjoyFun', icon: Globe, desc: 'Saldo global da plataforma' },
                  { id: 'event',  label: 'Cartão do Evento',  icon: Building2, desc: 'Saldo neste evento' },
                ].map(({ id, label, icon: Icon, desc }) => (
                  <button
                    key={id}
                    type="button"
                    onClick={() => setRechargeType(id)}
                    className={`flex flex-col items-start gap-1 p-3 rounded-xl border text-left transition-all ${
                      rechargeType === id
                        ? 'bg-purple-600/20 border-purple-500/60 text-white'
                        : 'bg-gray-900 border-gray-800 text-gray-400 hover:border-gray-600'
                    }`}
                  >
                    <div className="flex items-center gap-1.5">
                      <Icon size={14} className={rechargeType === id ? 'text-purple-400' : 'text-gray-500'} />
                      <span className="text-xs font-semibold">{label}</span>
                    </div>
                    <span className="text-[10px] text-gray-500 leading-tight">{desc}</span>
                  </button>
                ))}
              </div>
            </div>

            {/* Preset values */}
            <div>
              <p className="text-xs text-gray-500 uppercase tracking-widest mb-3">Valores rápidos</p>
              <div className="grid grid-cols-4 gap-2">
                {PRESET_VALUES.map(v => (
                  <button
                    key={v}
                    type="button"
                    onClick={() => setAmount(String(v))}
                    className={`py-2.5 rounded-xl text-sm font-semibold border transition-all active:scale-95 ${
                      parseFloat(amount) === v
                        ? 'bg-purple-600 border-purple-500 text-white'
                        : 'bg-gray-900 border-gray-800 text-gray-300 hover:border-gray-600'
                    }`}
                  >
                    R$ {v}
                  </button>
                ))}
              </div>
            </div>

            {/* Custom value input */}
            <div>
              <label className="block text-xs font-semibold text-gray-400 mb-1.5">
                Ou digite um valor personalizado
              </label>
              <div className="relative">
                <span className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-semibold text-sm">R$</span>
                <input
                  className="input w-full pl-10 text-white text-lg font-bold"
                  type="number"
                  min="1"
                  step="0.01"
                  placeholder="0,00"
                  value={amount}
                  onChange={e => setAmount(e.target.value)}
                />
              </div>
            </div>

            {/* Summary */}
            {parseFloat(amount) > 0 && (
              <div className="bg-gray-900/60 border border-gray-800 rounded-2xl p-4 flex items-center justify-between">
                <div className="flex items-center gap-2 text-gray-400 text-sm">
                  <Zap size={16} className="text-purple-400" />
                  Recarga via Pix
                </div>
                <span className="text-white font-bold text-lg">{formatCurrency(amount)}</span>
              </div>
            )}

            {/* Submit */}
            <button
              type="submit"
              disabled={loading || !parseFloat(amount)}
              className="w-full flex items-center justify-center gap-2 py-4 rounded-2xl font-bold text-white text-base transition-all active:scale-[0.98] disabled:opacity-60"
              style={{ background: 'linear-gradient(135deg, #7c3aed, #db2777)', boxShadow: '0 12px 32px rgba(124,58,237,0.4)' }}
            >
              {loading
                ? <><Loader2 size={18} className="animate-spin" /> Gerando...</>
                : 'Gerar QR Code Pix'}
            </button>
          </form>
        ) : (
          /* ── Step 2: QR Code gerado ──────────────────────────── */
          <div className="space-y-5">

            {/* Success header */}
            <div className="flex items-center gap-3 bg-green-500/10 border border-green-500/20 rounded-2xl p-4">
              <CheckCircle size={20} className="text-green-400 flex-shrink-0" />
              <div>
                <p className="text-green-400 font-semibold text-sm">Pix gerado com sucesso!</p>
                <p className="text-green-400/70 text-xs">
                  {pixResult?.recharge_type === 'global' ? '🌐 Carteira EnjoyFun' : '🏛️ Cartão do Evento'} — Escaneie ou copie o código.
                </p>
              </div>
            </div>

            {/* QR Code */}
            <div className="bg-white rounded-2xl p-6 flex flex-col items-center gap-3 shadow-xl shadow-purple-900/20">
              <QRCodeSVG
                value={pixResult.pix_code}
                size={200}
                bgColor="#ffffff"
                fgColor="#1e1b4b"
                level="M"
                marginSize={2}
              />
              <p className="text-gray-500 text-xs text-center">
                Valor: <span className="font-bold text-gray-800">{formatCurrency(pixResult.amount)}</span>
              </p>
            </div>

            {/* Pix code copy */}
            <div>
              <p className="text-xs text-gray-500 uppercase tracking-widest mb-2">Pix Copia e Cola</p>
              <div className="bg-gray-900/70 border border-gray-800 rounded-2xl p-4">
                <p className="text-gray-400 text-xs font-mono break-all leading-relaxed mb-3">
                  {pixResult.pix_code}
                </p>
                <button
                  onClick={handleCopy}
                  className={`w-full flex items-center justify-center gap-2 py-3 rounded-xl font-semibold text-sm transition-all active:scale-[0.97] ${
                    copied
                      ? 'bg-green-600/20 border border-green-600/40 text-green-400'
                      : 'bg-purple-600/20 border border-purple-600/40 text-purple-400 hover:bg-purple-600/30'
                  }`}
                >
                  {copied
                    ? <><CheckCircle size={16} /> Copiado!</>
                    : <><Copy size={16} /> Copiar Código Pix</>}
                </button>
              </div>
            </div>

            {/* Expiry note */}
            <p className="text-center text-xs text-gray-600">
              Este QR Code expira em 30 minutos.
            </p>

            {/* Back / New recharge */}
            <button
              onClick={() => { setPixResult(null); setAmount(''); setCopied(false); }}
              className="w-full py-3 rounded-xl border border-gray-800 text-gray-400 hover:text-white hover:border-gray-600 text-sm font-medium transition-all"
            >
              Gerar nova recarga
            </button>

            <button
              onClick={() => navigate(`/app/${slug}/home`)}
              className="w-full py-3 rounded-xl bg-gray-900 text-gray-300 text-sm font-semibold transition-all hover:bg-gray-800"
            >
              Voltar ao Dashboard
            </button>
          </div>
        )}

        <p className="text-xs text-gray-700 text-center pb-2">
          © {new Date().getFullYear()} EnjoyFun · Pagamento 100% seguro
        </p>
      </div>
    </div>
  );
}
