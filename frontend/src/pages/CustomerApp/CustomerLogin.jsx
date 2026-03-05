import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import {
  Mail, MessageCircle, Zap, ArrowRight,
  RotateCcw, CheckCircle, Smartphone, RefreshCw,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { requestCodeApi, verifyCodeApi } from '../../api/auth';

// TODO: Em produção, buscar o organizer_id real via slug no backend
const MOCK_ORGANIZER_ID = 1;

// ── Helpers ────────────────────────────────────────────────────────────────
const isEmail = (v) => v.includes('@');

/** Formata somente dígitos como telefone BR: (11) 99999-9999 */
function formatPhone(raw) {
  const d = raw.replace(/\D/g, '').slice(0, 11);
  if (d.length <= 2)  return d;
  if (d.length <= 6)  return `(${d.slice(0, 2)}) ${d.slice(2)}`;
  if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

/** Retorna apenas dígitos (para enviar ao backend) */
const rawPhone = (v) => v.replace(/\D/g, '');

// ──────────────────────────────────────────────────────────────────────────
export default function CustomerLogin() {
  const { slug }   = useParams();
  const navigate   = useNavigate();

  const [step, setStep]             = useState(1);
  const [identifier, setIdentifier] = useState('');   // display value
  const [otp, setOtp]               = useState('');
  const [loading, setLoading]       = useState(false);

  // The actual value we send to the API (raw phone or e-mail as-is)
  const apiIdentifier = isEmail(identifier) ? identifier.trim() : rawPhone(identifier);
  const inputIsEmail  = isEmail(identifier);

  // ── Input handler — apply phone mask on the fly ────────────────────────
  const handleIdentifierChange = (e) => {
    const val = e.target.value;
    // If it looks like a phone (no @ and starts with digits / spaces / parens)
    if (!val.includes('@') && /^[\d\s()+\-]*$/.test(val)) {
      setIdentifier(formatPhone(val));
    } else {
      setIdentifier(val);
    }
  };

  // ── Step 1: Solicitar código ───────────────────────────────────────────
  const handleRequestCode = async (e) => {
    e.preventDefault();
    if (!apiIdentifier) return toast.error('Informe seu e-mail ou WhatsApp.');

    setLoading(true);
    try {
      await requestCodeApi(apiIdentifier, MOCK_ORGANIZER_ID);
      const channel = inputIsEmail ? 'e-mail' : 'WhatsApp/SMS';
      toast.success(`Código enviado via ${channel}!`);
      setStep(2);
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erro ao enviar código. Tente novamente.');
    } finally {
      setLoading(false);
    }
  };

  // ── Step 2: Verificar código e autenticar ──────────────────────────────
  const handleVerifyOTP = async (e) => {
    e.preventDefault();
    if (otp.length !== 6) return toast.error('O código deve ter 6 dígitos.');

    setLoading(true);
    try {
      const result = await verifyCodeApi(apiIdentifier, otp.trim(), MOCK_ORGANIZER_ID);

      // Persiste sessão idêntico ao login normal
      localStorage.setItem('access_token',  result.access_token);
      localStorage.setItem('refresh_token', result.refresh_token);
      localStorage.setItem('enjoyfun_user', JSON.stringify(result.user));

      toast.success(`Bem-vindo, ${result.user?.name || 'Cliente'}! 🎉`);
      navigate(`/app/${slug}/home`, { replace: true });
    } catch (err) {
      const status = err?.response?.status;
      if (status === 401) {
        toast.error('Código inválido ou expirado. Solicite um novo.');
      } else {
        toast.error(err?.response?.data?.message || 'Erro ao verificar código.');
      }
    } finally {
      setLoading(false);
    }
  };

  // ── Retry / change method ──────────────────────────────────────────────
  const handleRetry = () => {
    setStep(1);
    setIdentifier('');
    setOtp('');
  };

  // ── Dynamic step-2 instruction ────────────────────────────────────────
  const step2Instruction = inputIsEmail
    ? `Enviamos um código para o e-mail ${identifier}.`
    : `Enviamos um código via WhatsApp/SMS para ${identifier}.`;

  // ─────────────────────────────────────────────────────────────────────
  return (
    <div
      className="min-h-screen bg-gray-950 flex flex-col"
      style={{ background: 'radial-gradient(ellipse at top, rgba(124,58,237,0.15) 0%, #030712 60%)' }}
    >
      <div className="flex-1 flex flex-col items-center justify-center px-6 max-w-md mx-auto w-full">

        {/* Logo */}
        <div className="flex flex-col items-center mb-8">
          <div
            className="w-16 h-16 rounded-3xl bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center mb-4"
            style={{ boxShadow: '0 0 40px rgba(124,58,237,0.5)' }}
          >
            <Zap size={32} className="text-white" />
          </div>
          <h1 className="text-2xl font-extrabold text-white tracking-tight">EnjoyFun</h1>
          {slug && (
            <span className="mt-1 text-xs text-purple-400 font-medium bg-purple-500/10 border border-purple-500/20 px-3 py-0.5 rounded-full capitalize">
              {slug.replace(/-/g, ' ')}
            </span>
          )}
        </div>

        {/* Card */}
        <div className="w-full bg-gray-900/70 border border-gray-800 rounded-2xl overflow-hidden backdrop-blur-sm shadow-2xl">

          {/* Header */}
          <div className="p-6 border-b border-gray-800/60">
            <div className="flex items-center gap-3 mb-1">
              {step === 1 ? (
                <div className="flex items-center gap-1.5">
                  <Mail size={18} className="text-blue-400" />
                  <span className="text-gray-600 text-xs font-semibold">ou</span>
                  <MessageCircle size={18} className="text-green-400" />
                </div>
              ) : (
                <CheckCircle size={20} className="text-purple-400" />
              )}
              <h2 className="text-lg font-bold text-white">
                {step === 1 ? 'Acesse o Evento' : 'Confirme o código'}
              </h2>
            </div>
            <p className="text-gray-500 text-sm pl-1">
              {step === 1
                ? 'Informe seu e-mail ou WhatsApp com DDD. Enviaremos seu código de acesso.'
                : step2Instruction
              }
            </p>
          </div>

          {/* Form body */}
          <div className="p-6">
            {step === 1 ? (
              <form onSubmit={handleRequestCode} className="space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-400 mb-1.5 flex items-center gap-1.5">
                    {!identifier || inputIsEmail
                      ? <Mail size={12} className="text-blue-400" />
                      : <MessageCircle size={12} className="text-green-400" />
                    }
                    {!identifier ? 'E-mail ou WhatsApp com DDD'
                      : inputIsEmail ? 'E-mail' : 'WhatsApp com DDD'}
                    {' *'}
                  </label>
                  <div className="relative">
                    {/* Left icon indicator */}
                    <div className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
                      {!identifier || inputIsEmail
                        ? <Mail size={16} className="text-gray-600" />
                        : <MessageCircle size={16} className="text-green-500/70" />
                      }
                    </div>
                    <input
                      autoFocus
                      className="input w-full pl-10"
                      type={inputIsEmail ? 'email' : 'text'}
                      inputMode={inputIsEmail ? 'email' : 'tel'}
                      placeholder="seu@email.com  ou  (11) 99999-9999"
                      value={identifier}
                      onChange={handleIdentifierChange}
                      disabled={loading}
                    />
                  </div>
                </div>

                <button
                  type="submit"
                  disabled={loading || !apiIdentifier}
                  className="w-full flex items-center justify-center gap-2 py-3.5 rounded-xl font-semibold text-sm text-white transition-all active:scale-[0.98] disabled:opacity-60"
                  style={{ background: 'linear-gradient(135deg, #7c3aed, #db2777)', boxShadow: '0 8px 24px rgba(124,58,237,0.4)' }}
                >
                  {loading
                    ? <RefreshCw size={16} className="animate-spin" />
                    : <><Smartphone size={16} /> Receber código de acesso</>
                  }
                </button>
              </form>
            ) : (
              <form onSubmit={handleVerifyOTP} className="space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-400 mb-1.5">
                    Código de 6 dígitos *
                  </label>
                  <input
                    autoFocus
                    className="input w-full text-center text-2xl tracking-[0.5em] font-bold"
                    type="text"
                    inputMode="numeric"
                    maxLength={6}
                    placeholder="000000"
                    value={otp}
                    onChange={(e) => setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
                    disabled={loading}
                  />
                </div>

                <button
                  type="submit"
                  disabled={loading || otp.length !== 6}
                  className="w-full flex items-center justify-center gap-2 py-3.5 rounded-xl font-semibold text-sm text-white transition-all active:scale-[0.98] disabled:opacity-60"
                  style={{ background: 'linear-gradient(135deg, #7c3aed, #db2777)', boxShadow: '0 8px 24px rgba(124,58,237,0.4)' }}
                >
                  {loading
                    ? <RefreshCw size={16} className="animate-spin" />
                    : <>Entrar <ArrowRight size={16} /></>
                  }
                </button>

                {/* Escape hatch */}
                <div className="pt-1 space-y-1.5 text-center">
                  <button
                    type="button"
                    onClick={() => { setStep(1); setOtp(''); }}
                    className="flex items-center justify-center gap-1.5 w-full py-2 text-xs text-gray-500 hover:text-gray-300 transition-colors"
                  >
                    <RotateCcw size={11} /> Não recebeu o código? Tentar outro método
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>

        {/* Progress dots */}
        <div className="flex gap-2 mt-6">
          {[1, 2].map(s => (
            <div
              key={s}
              className={`h-1.5 rounded-full transition-all duration-300 ${s === step ? 'w-6 bg-purple-500' : 'w-2 bg-gray-700'}`}
            />
          ))}
        </div>

        {/* Dual-method hint below card */}
        <div className="mt-5 flex items-center gap-3 text-xs text-gray-600">
          <Mail size={12} className="text-blue-500/50" />
          <span>E-mail</span>
          <span className="w-1 h-1 bg-gray-700 rounded-full" />
          <MessageCircle size={12} className="text-green-500/50" />
          <span>WhatsApp / SMS</span>
        </div>

        <p className="mt-6 text-xs text-gray-700 text-center">
          © {new Date().getFullYear()} EnjoyFun · Plataforma de Eventos
        </p>
      </div>
    </div>
  );
}
