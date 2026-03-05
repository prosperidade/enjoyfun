import { useState } from 'react';
import { Navigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Eye, EyeOff, Zap, LogIn, UserPlus, ArrowRight, Check } from 'lucide-react';
import toast from 'react-hot-toast';

const perks = [
  'Gestão completa de eventos',
  'Cartão digital & PDV offline',
  'Bot WhatsApp integrado',
  'Agentes de IA especializados',
];

export default function Login() {
  const { user, login, register } = useAuth();
  const [tab, setTab]         = useState('login');
  const [loading, setLoading] = useState(false);
  const [showPw, setShowPw]   = useState(false);
  const [form, setForm]       = useState({ name: '', email: '', password: '', phone: '', cpf: '' });
  const [errors, setErrors]   = useState({});

  if (user) return <Navigate to="/" replace />;

  const set = (k) => (e) => {
    setForm(f => ({ ...f, [k]: e.target.value }));
    if (errors[k]) setErrors(er => ({ ...er, [k]: null }));
  };

  const validate = () => {
    const e = {};
    if (tab === 'register' && !form.name.trim())         e.name     = 'Nome obrigatório.';
    if (!form.email || !/\S+@\S+\.\S+/.test(form.email)) e.email    = 'E-mail inválido.';
    if (form.password.length < (tab === 'register' ? 8 : 1)) e.password = tab === 'register' ? 'Mínimo 8 caracteres.' : 'Senha obrigatória.';
    if (tab === 'register' && !form.cpf.trim())          e.cpf      = 'CPF obrigatório.';
    if (tab === 'register' && !form.phone.trim())        e.phone    = 'Telefone obrigatório.';
    return e;
  };

  const handleSubmit = async (e) => {
    if (e?.preventDefault) e.preventDefault();
    console.log('[Login] handle submit disparado', tab, form.email);
    
    const errs = validate();
    if (Object.keys(errs).length) { setErrors(errs); return; }

    setLoading(true);
    try {
      if (tab === 'login') {
        await login(form.email, form.password);
        toast.success('Bem-vindo de volta! 🎉');
      } else {
        await register({ name: form.name, email: form.email, password: form.password, phone: form.phone, cpf: form.cpf });
        toast.success('Conta criada! Bem-vindo ao EnjoyFun! 🚀');
      }
    } catch (err) {
      console.error('[Login] Erro:', err);
      console.log('RESPOSTA COMPLETA DA API (ERRO):', err?.response?.data || err?.response || err);
      const msg = err?.response?.data?.message || 'Ocorreu um erro. Tente novamente.';
      toast.error(msg);
      if (err?.response?.data?.errors) setErrors(err.response.data.errors);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-950 flex">

      {/* ── Left panel — Branding ───────────────────────────────── */}
      <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-gray-900 flex-col justify-between p-12">

        {/* Gradient blobs */}
        <div className="absolute inset-0 pointer-events-none">
          <div className="absolute top-0 left-0 w-[500px] h-[500px] bg-purple-700/20 rounded-full blur-[120px] -translate-x-1/2 -translate-y-1/2" />
          <div className="absolute bottom-0 right-0 w-[400px] h-[400px] bg-pink-700/20 rounded-full blur-[100px] translate-x-1/3 translate-y-1/3" />
          <div className="absolute top-1/2 left-1/2 w-[300px] h-[300px] bg-cyan-700/10 rounded-full blur-[80px] -translate-x-1/2 -translate-y-1/2" />
        </div>

        {/* Logo */}
        <div className="relative flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center" style={{boxShadow: '0 0 24px rgba(124,58,237,0.5)'}}>
            <Zap size={20} className="text-white" />
          </div>
          <span className="text-white font-bold text-xl tracking-tight">EnjoyFun</span>
          <span className="text-gray-500 text-sm">v2.0</span>
        </div>

        {/* Hero */}
        <div className="relative space-y-8">
          <div>
            <h2 className="text-4xl font-extrabold text-white leading-tight">
              A plataforma que
              <br />
              <span style={{background: 'linear-gradient(to right, #a78bfa, #f472b6)', WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent'}}>
                transforma eventos
              </span>
              <br />
              em experiências.
            </h2>
            <p className="text-gray-400 mt-4 text-base leading-relaxed max-w-sm">
              Gestão inteligente do início ao fim — ingressos, bar, estacionamento, cartão digital e IA numa única plataforma.
            </p>
          </div>

          {/* Feature list */}
          <ul className="space-y-3">
            {perks.map(p => (
              <li key={p} className="flex items-center gap-3 text-sm text-gray-300">
                <div className="w-5 h-5 rounded-full bg-purple-800/60 border border-purple-700/40 flex items-center justify-center flex-shrink-0">
                  <Check size={11} className="text-purple-400" />
                </div>
                {p}
              </li>
            ))}
          </ul>
        </div>

        {/* Testimonial */}
        <div className="relative">
          <div className="bg-gray-800/60 border border-gray-700/40 rounded-2xl p-5 backdrop-blur-sm">
            <p className="text-sm text-gray-300 italic leading-relaxed">
              "O EnjoyFun reduziu nosso tempo de gestão em 70% e triplicou a velocidade de atendimento no bar."
            </p>
            <div className="flex items-center gap-2.5 mt-3">
              <div className="w-8 h-8 rounded-full bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center text-xs font-bold text-white">M</div>
              <div>
                <p className="text-xs font-semibold text-white">Marcos Alves</p>
                <p className="text-xs text-gray-500">Produtor · Festival Sul 2024</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ── Right panel — Form ──────────────────────────────────── */}
      <div className="flex-1 flex items-center justify-center p-6 sm:p-12 relative">

        {/* Background blobs (mobile) */}
        <div className="lg:hidden fixed inset-0 pointer-events-none overflow-hidden">
          <div className="absolute -top-20 -left-20 w-72 h-72 bg-purple-900/20 rounded-full blur-3xl" />
          <div className="absolute -bottom-20 -right-20 w-72 h-72 bg-pink-900/20 rounded-full blur-3xl" />
        </div>

        <div className="w-full max-w-md relative">

          {/* Mobile logo */}
          <div className="lg:hidden flex items-center justify-center gap-2.5 mb-8">
            <div className="w-10 h-10 rounded-2xl bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center" style={{boxShadow: '0 0 20px rgba(124,58,237,0.5)'}}>
              <Zap size={20} className="text-white" />
            </div>
            <span className="text-white font-bold text-xl">EnjoyFun</span>
          </div>

          {/* Heading */}
          <div className="mb-8">
            <h1 className="text-2xl font-bold text-white">
              {tab === 'login' ? 'Acessar plataforma' : 'Criar conta'}
            </h1>
            <p className="text-gray-400 text-sm mt-1">
              {tab === 'login' ? 'Entre com suas credenciais.' : 'Preencha os dados para começar.'}
            </p>
          </div>

          {/* Tab switcher */}
          <div className="flex bg-gray-900 border border-gray-800 rounded-xl p-1 mb-6">
            <TabBtn active={tab === 'login'} onClick={() => { setTab('login'); setErrors({}); }}>
              <LogIn size={14} /> Entrar
            </TabBtn>
            <TabBtn active={tab === 'register'} onClick={() => { setTab('register'); setErrors({}); }}>
              <UserPlus size={14} /> Cadastrar
            </TabBtn>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} noValidate className="space-y-4">
            {tab === 'register' && (
              <Field label="Nome completo" error={errors.name}>
                <input className={input(errors.name)} type="text" placeholder="Seu nome" value={form.name} onChange={set('name')} autoFocus />
              </Field>
            )}

            <Field label="E-mail" error={errors.email}>
              <input className={input(errors.email)} type="email" placeholder="seu@email.com" value={form.email} onChange={set('email')} autoFocus={tab === 'login'} />
            </Field>

            {tab === 'register' && (
              <Field label="CPF *" error={errors.cpf}>
                <input className={input(errors.cpf)} type="text" placeholder="000.000.000-00" required value={form.cpf} onChange={set('cpf')} />
              </Field>
            )}

            {tab === 'register' && (
              <Field label="WhatsApp / Telefone *" error={errors.phone}>
                <input className={input(errors.phone)} type="tel" placeholder="+55 11 99999-0000" required value={form.phone} onChange={set('phone')} />
              </Field>
            )}

            <Field label="Senha" error={errors.password}>
              <div className="relative">
                <input
                  className={`${input(errors.password)} pr-11`}
                  type={showPw ? 'text' : 'password'}
                  placeholder={tab === 'register' ? 'Mínimo 8 caracteres' : '••••••••'}
                  value={form.password}
                  onChange={set('password')}
                />
                <button
                  type="button"
                  onClick={() => setShowPw(v => !v)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300 transition-colors"
                  aria-label={showPw ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPw ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </div>
            </Field>

            <button
              type="submit"
              onClick={handleSubmit}
              disabled={loading}
              className="w-full flex items-center justify-center gap-2 py-3 px-6 rounded-xl font-semibold text-sm text-white transition-all duration-200 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed mt-2"
              style={{background: 'linear-gradient(135deg, #7c3aed, #db2777)', boxShadow: loading ? 'none' : '0 8px 24px rgba(124,58,237,0.4)'}}
            >
              {loading
                ? <span className="spinner w-5 h-5" />
                : <>{tab === 'login' ? 'Entrar na plataforma' : 'Criar minha conta'} <ArrowRight size={16} /></>}
            </button>
          </form>

          {/* Demo credentials */}
          {tab === 'login' && (
            <button
              type="button"
              onClick={() => setForm(f => ({ ...f, email: 'admin@enjoyfun.com', password: 'password' }))}
              className="mt-4 w-full py-2.5 px-4 rounded-xl border border-dashed border-gray-700 hover:border-purple-700/60 text-xs text-gray-500 hover:text-gray-300 transition-all duration-200 flex items-center justify-center gap-2"
            >
              🔑 Preencher credenciais de demonstração
            </button>
          )}

          {/* Footer */}
          <p className="text-center text-xs text-gray-600 mt-8">
            © 2025 EnjoyFun · Plataforma de Eventos &nbsp;·&nbsp;{' '}
            <span className="text-gray-500 hover:text-gray-300 cursor-pointer transition-colors">Termos de uso</span>
          </p>
        </div>
      </div>
    </div>
  );
}

// ── Sub-components ─────────────────────────────────────────────────────────

function TabBtn({ active, onClick, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex-1 py-2 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center gap-1.5 ${
        active ? 'bg-purple-700 text-white shadow-lg' : 'text-gray-400 hover:text-gray-200'
      }`}
    >
      {children}
    </button>
  );
}

function Field({ label, error, children }) {
  return (
    <div>
      <label className="input-label">{label}</label>
      {children}
      {error && <p className="text-red-400 text-xs mt-1.5 flex items-center gap-1">⚠ {error}</p>}
    </div>
  );
}

function input(hasError) {
  return `input ${hasError ? 'border-red-700 focus:border-red-500 focus:ring-red-700/30' : ''}`;
}
