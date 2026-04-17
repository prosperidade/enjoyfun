import { useState } from 'react';
import { Navigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Eye, EyeOff, Zap, LogIn, UserPlus, ArrowRight, Check } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../lib/api';

const perks = [
  'Gestão completa de eventos',
  'Cartão digital & PDV offline',
  'Bot WhatsApp integrado',
  'Agentes de IA especializados',
];

export default function Login() {
  const { user, login } = useAuth();
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
    const errs = validate();
    if (Object.keys(errs).length) { setErrors(errs); return; }

    setLoading(true);
    try {
      if (tab === 'login') {
        await login(form.email, form.password);
        toast.success('Bem-vindo de volta! 🎉');
      } else {
        const res = await api.post('/auth/register', { name: form.name, email: form.email, password: form.password, phone: form.phone, cpf: form.cpf });
        const regData = res.data?.data;
        if (regData?.status === 'pending') {
          toast.success(res.data?.message || 'Cadastro enviado! Aguarde aprovacao.');
          setTab('login');
          return;
        }
        // Legacy: if backend still returns tokens
        toast.success('Conta criada!');
      }
    } catch (err) {
      const msg = err?.response?.data?.message || 'Ocorreu um erro. Tente novamente.';
      toast.error(msg);
      if (err?.response?.data?.errors) setErrors(err.response.data.errors);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex" style={{backgroundColor: 'var(--an-bg)'}}>

      {/* ── Left panel — Branding ───────────────────────────────── */}
      <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden flex-col justify-between p-12" style={{backgroundColor: 'var(--an-surface-dim)'}}>

        {/* Gradient blobs */}
        <div className="absolute inset-0 pointer-events-none">
          <div className="absolute top-0 left-0 w-[500px] h-[500px] bg-cyan-500/20 rounded-full blur-[120px] -translate-x-1/2 -translate-y-1/2 animate-pulse" style={{animationDuration: '8s'}} />
          <div className="absolute bottom-0 right-0 w-[400px] h-[400px] bg-purple-500/20 rounded-full blur-[100px] translate-x-1/3 translate-y-1/3 animate-pulse" style={{animationDuration: '10s'}} />
          <div className="absolute top-1/2 left-1/2 w-[300px] h-[300px] bg-cyan-700/10 rounded-full blur-[80px] -translate-x-1/2 -translate-y-1/2" />
        </div>

        {/* Logo */}
        <div className="relative flex items-center gap-3">
          <img
            src="/images/logoenjoyfun.png"
            alt="EnjoyFun Logo"
            className="w-10 h-10 object-contain rounded-xl shadow-[0_0_24px_rgba(0,240,255,0.4)]"
          />
          <span className="text-slate-100 font-bold text-xl tracking-tight font-headline">EnjoyFun</span>
          <span className="text-slate-600 text-sm">v2.0</span>
        </div>

        {/* Hero */}
        <div className="relative space-y-8">
          <div>
            <h2 className="text-4xl font-extrabold text-slate-100 leading-tight font-headline">
              A plataforma que
              <br />
              <span className="text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-purple-400">
                transforma eventos
              </span>
              <br />
              em experiências.
            </h2>
            <p className="text-slate-400 mt-4 text-base leading-relaxed max-w-sm">
              Gestão inteligente do início ao fim — ingressos, bar, estacionamento, cartão digital e IA numa única plataforma.
            </p>
          </div>

          {/* Feature list */}
          <ul className="space-y-3">
            {perks.map(p => (
              <li key={p} className="flex items-center gap-3 text-sm text-slate-300">
                <div className="w-5 h-5 rounded-full bg-cyan-500/20 border border-cyan-500/30 flex items-center justify-center flex-shrink-0">
                  <Check size={11} className="text-cyan-400" />
                </div>
                {p}
              </li>
            ))}
          </ul>
        </div>

        {/* Testimonial */}
        <div className="relative">
          <div className="rounded-2xl p-5 backdrop-blur-sm bg-slate-800/40 border border-cyan-500/10">
            <p className="text-sm text-slate-300 italic leading-relaxed">
              "O EnjoyFun reduziu nosso tempo de gestão em 70% e triplicou a velocidade de atendimento no bar."
            </p>
            <div className="flex items-center gap-2.5 mt-3">
              <div className="w-8 h-8 rounded-full bg-gradient-to-br from-cyan-600 to-purple-600 flex items-center justify-center text-xs font-bold text-white">M</div>
              <div>
                <p className="text-xs font-semibold text-slate-100">Marcos Alves</p>
                <p className="text-xs text-slate-500">Produtor · Festival Sul 2024</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ── Right panel — Form ──────────────────────────────────── */}
      <div className="flex-1 flex items-center justify-center p-6 sm:p-12 relative">

        {/* Background blobs (mobile) */}
        <div className="lg:hidden fixed inset-0 pointer-events-none overflow-hidden">
          <div className="absolute -top-20 -left-20 w-72 h-72 bg-cyan-900/20 rounded-full blur-3xl" />
          <div className="absolute -bottom-20 -right-20 w-72 h-72 bg-purple-900/20 rounded-full blur-3xl" />
        </div>

        <div className="w-full max-w-md relative">

          {/* Mobile logo */}
          <div className="lg:hidden flex items-center justify-center gap-2.5 mb-8">
            <img
              src="/images/logoenjoyfun.png"
              alt="EnjoyFun Logo"
              className="w-10 h-10 object-contain rounded-xl shadow-[0_0_24px_rgba(0,240,255,0.4)]"
            />
            <span className="text-slate-100 font-bold text-xl font-headline">EnjoyFun</span>
          </div>

          {/* Heading */}
          <div className="mb-8">
            <h1 className="text-2xl font-bold text-slate-100 font-headline">
              {tab === 'login' ? 'Acessar plataforma' : 'Criar conta'}
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              {tab === 'login' ? 'Entre com suas credenciais.' : 'Preencha os dados para começar.'}
            </p>
          </div>

          {/* Tab switcher */}
          <div className="flex bg-slate-800/50 border border-slate-700/50 rounded-xl p-1 mb-6">
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
                <input className={input(errors.name)} id="register-name" name="name" autoComplete="name" type="text" placeholder="Seu nome" value={form.name} onChange={set('name')} autoFocus />
              </Field>
            )}

            <Field label="E-mail" error={errors.email}>
              <input className={input(errors.email)} id="auth-email" name="email" autoComplete="email" type="email" placeholder="seu@email.com" value={form.email} onChange={set('email')} autoFocus={tab === 'login'} />
            </Field>

            {tab === 'register' && (
              <Field label="CPF *" error={errors.cpf}>
                <input className={input(errors.cpf)} id="register-cpf" name="cpf" autoComplete="off" type="text" placeholder="000.000.000-00" required value={form.cpf} onChange={set('cpf')} />
              </Field>
            )}

            {tab === 'register' && (
              <Field label="WhatsApp / Telefone *" error={errors.phone}>
                <input className={input(errors.phone)} id="register-phone" name="phone" autoComplete="tel" type="tel" placeholder="+55 11 99999-0000" required value={form.phone} onChange={set('phone')} />
              </Field>
            )}

            <Field label="Senha" error={errors.password}>
              <div className="relative">
                <input
                  className={`${input(errors.password)} pr-11`}
                  id="auth-password"
                  name="password"
                  autoComplete={tab === 'login' ? 'current-password' : 'new-password'}
                  type={showPw ? 'text' : 'password'}
                  placeholder={tab === 'register' ? 'Mínimo 8 caracteres' : '••••••••'}
                  value={form.password}
                  onChange={set('password')}
                />
                <button
                  type="button"
                  onClick={() => setShowPw(v => !v)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition-colors"
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
              className="w-full flex items-center justify-center gap-2 py-3 px-6 rounded-xl font-bold text-sm transition-all duration-200 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed mt-2"
              style={{background: 'linear-gradient(to right, #06B6D4, #22D3EE)', color: '#0B0F19', boxShadow: loading ? 'none' : '0 0 20px rgba(0,240,255,0.3)'}}
            >
              {loading
                ? <span className="spinner w-5 h-5" />
                : <>{tab === 'login' ? 'Entrar na plataforma' : 'Criar minha conta'} <ArrowRight size={16} /></>}
            </button>
          </form>

          {/* Demo credentials — only visible in development builds */}
          {import.meta.env.DEV && tab === 'login' && (
            <button
              type="button"
              onClick={() => setForm(f => ({ ...f, email: 'admin@enjoyfun.com', password: 'password' }))}
              className="mt-4 w-full py-2.5 px-4 rounded-xl border border-dashed border-slate-700 hover:border-cyan-500/40 text-xs text-slate-500 hover:text-slate-300 transition-all duration-200 flex items-center justify-center gap-2"
            >
              Preencher credenciais de demonstração
            </button>
          )}

          {/* Footer */}
          <p className="text-center text-xs text-slate-600 mt-8">
            © 2025 EnjoyFun · Plataforma de Eventos &nbsp;·&nbsp;{' '}
            <span className="text-slate-500 hover:text-slate-300 cursor-pointer transition-colors">Termos de uso</span>
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
        active ? 'bg-cyan-500 text-slate-950 font-semibold shadow-lg' : 'text-slate-400 hover:text-slate-200'
      }`}
    >
      {children}
    </button>
  );
}

function Field({ label, error, children }) {
  return (
    <div>
      <label className="an-label">{label}</label>
      {children}
      {error && <p className="text-red-400 text-xs mt-1.5 flex items-center gap-1">⚠ {error}</p>}
    </div>
  );
}

function input(hasError) {
  return `an-input ${hasError ? 'border-red-700 focus:border-red-500 focus:ring-red-700/30' : ''}`;
}
