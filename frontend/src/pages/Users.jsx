import { useEffect, useState } from 'react';
import { Users as UsersIcon, Plus, UserCheck, UserX, Shield, ChevronDown } from 'lucide-react';
import api from '../lib/api';
import toast from 'react-hot-toast';

const ROLE_LABELS = { manager: 'Gerente', cashier: 'Caixa' };
const SECTOR_LABELS = { bar: 'Bar', food: 'Alimentação', shop: 'Loja', all: 'Todos' };

const ROLE_COLORS = {
  manager: 'bg-purple-500/15 text-purple-400 border border-purple-500/30',
  cashier: 'bg-cyan-500/15 text-cyan-400 border border-cyan-500/30',
};
const SECTOR_COLORS = {
  bar: 'bg-amber-500/15 text-amber-400 border border-amber-500/30',
  food: 'bg-orange-500/15 text-orange-400 border border-orange-500/30',
  shop: 'bg-cyan-500/15 text-cyan-400 border border-cyan-500/30',
  all: 'bg-green-500/15 text-green-400 border border-green-500/30',
};

function blankForm() {
  return { name: '', email: '', password: '', phone: '', cpf: '', role: 'cashier', sector: 'all' };
}

export default function Users() {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState(blankForm());
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    api.get('/users')
      .then(r => setUsers(r.data.data || []))
      .catch(() => toast.error('Erro ao carregar usuários.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const toggleActive = async (id, current) => {
    try {
      await api.patch(`/users/${id}`, { is_active: current ? 0 : 1 });
      setUsers(u => u.map(user => user.id === id ? { ...user, is_active: !current } : user));
      toast.success(current ? 'Usuário desativado.' : 'Usuário ativado.');
    } catch { toast.error('Erro ao atualizar.'); }
  };

  const handleCreate = async (e) => {
    e.preventDefault();
    if (!form.name || !form.email || !form.password) {
      return toast.error('Nome, e-mail e senha são obrigatórios.');
    }
    setSaving(true);
    try {
      const { data } = await api.post('/users', form);
      toast.success(data.message || 'Usuário criado!');
      setShowModal(false);
      setForm(blankForm());
      load();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao criar usuário.');
    } finally {
      setSaving(false);
    }
  };

  const field = (key) => ({
    value: form[key],
    onChange: (e) => setForm(f => ({ ...f, [key]: e.target.value })),
  });

  return (
    <div className="space-y-6 relative">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold font-headline text-slate-100 flex items-center gap-2">
            <UsersIcon size={22} className="text-cyan-400" /> Equipe / Staff
          </h1>
          <p className="text-slate-500 text-sm">{users.length} membro(s) cadastrado(s)</p>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex items-center gap-2 transition-colors"
        >
          <Plus size={18} /> Novo Membro
        </button>
      </div>

      {/* Table */}
      <div className="overflow-x-auto rounded-2xl border border-slate-800/40 bg-[#111827]">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-slate-800/50">
              <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Nome</th>
              <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Email</th>
              <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Telefone</th>
              <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Cargo</th>
              <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Setor</th>
              <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Status</th>
              <th className="text-left px-4 py-3 text-slate-400 uppercase text-xs tracking-wider font-semibold">Cadastrado em</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800/40">
            {loading ? (
              <tr><td colSpan={7} className="text-center py-10"><div className="spinner w-6 h-6 mx-auto" /></td></tr>
            ) : users.length === 0 ? (
              <tr><td colSpan={7} className="text-center py-10 text-slate-500 text-sm">Nenhum membro cadastrado. Clique em "Novo Membro" para começar.</td></tr>
            ) : users.map(u => (
              <tr key={u.id} className="hover:bg-slate-800/30 transition-colors">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-full bg-gradient-to-br from-cyan-950 to-slate-800 border border-slate-700/50 flex items-center justify-center text-xs font-bold text-slate-100 flex-shrink-0">
                      {u.name?.charAt(0).toUpperCase()}
                    </div>
                    <span className="font-medium text-slate-100">{u.name}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-slate-400">{u.email}</td>
                <td className="px-4 py-3 text-slate-400">{u.phone || '—'}</td>
                <td className="px-4 py-3">
                  <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${ROLE_COLORS[u.role] || 'bg-slate-800/40 text-slate-300'}`}>
                    {ROLE_LABELS[u.role] || u.role || '—'}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${SECTOR_COLORS[u.sector] || 'bg-slate-800/40 text-slate-300'}`}>
                    {SECTOR_LABELS[u.sector] || u.sector || '—'}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <button
                    onClick={() => toggleActive(u.id, u.is_active)}
                    className={`flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full cursor-pointer hover:opacity-80 transition-opacity ${u.is_active ? 'bg-green-500/15 text-green-400 border border-green-500/30' : 'bg-red-500/15 text-red-400 border border-red-500/30'}`}
                  >
                    {u.is_active ? <UserCheck size={12} /> : <UserX size={12} />}
                    {u.is_active ? 'Ativo' : 'Inativo'}
                  </button>
                </td>
                <td className="px-4 py-3 text-xs text-slate-500">{new Date(u.created_at).toLocaleDateString('pt-BR')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Create User Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
            <div className="p-6 border-b border-slate-700/50 flex justify-between items-center">
              <h2 className="text-lg font-bold text-slate-100 font-headline flex items-center gap-2">
                <Shield size={20} className="text-cyan-400" />
                Cadastrar Novo Membro da Equipe
              </h2>
              <button onClick={() => setShowModal(false)} className="text-slate-400 hover:text-slate-100 text-xl leading-none transition-colors">&#10005;</button>
            </div>

            <form onSubmit={handleCreate} className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="col-span-2">
                  <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Nome Completo *</label>
                  <input autoFocus className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full transition-colors" placeholder="Ex: João da Silva" {...field('name')} />
                </div>

                <div>
                  <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">CPF</label>
                  <input className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full transition-colors" placeholder="000.000.000-00" {...field('cpf')} />
                </div>

                <div>
                  <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Telefone</label>
                  <input className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full transition-colors" placeholder="(11) 9 0000-0000" {...field('phone')} />
                </div>

                <div className="col-span-2">
                  <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">E-mail *</label>
                  <input className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full transition-colors" type="email" placeholder="email@exemplo.com" {...field('email')} />
                </div>

                <div className="col-span-2">
                  <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Senha de Acesso *</label>
                  <input className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 placeholder-slate-500 outline-none w-full transition-colors" type="password" placeholder="Mínimo 6 caracteres" {...field('password')} />
                </div>

                <div>
                  <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Cargo / Permissão</label>
                  <div className="relative">
                    <select className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 outline-none w-full pr-8 appearance-none transition-colors" {...field('role')}>
                      <option value="cashier">Caixa</option>
                      <option value="manager">Gerente</option>
                    </select>
                    <ChevronDown size={14} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
                  </div>
                </div>

                <div>
                  <label className="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Setor de Atuação</label>
                  <div className="relative">
                    <select className="bg-slate-800/50 border border-slate-700/50 focus:border-cyan-500 rounded-xl px-3 py-2 text-slate-100 outline-none w-full pr-8 appearance-none transition-colors" {...field('sector')}>
                      <option value="all">Todos os Setores</option>
                      <option value="bar">Bar</option>
                      <option value="food">Alimentação</option>
                      <option value="shop">Loja</option>
                    </select>
                    <ChevronDown size={14} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
                  </div>
                </div>
              </div>

              <div className="pt-2 flex gap-3">
                <button type="button" onClick={() => setShowModal(false)} className="border border-slate-700/50 text-slate-300 hover:border-cyan-500/30 rounded-xl px-4 py-2 font-medium flex-1 transition-colors">
                  Cancelar
                </button>
                <button type="submit" disabled={saving} className="bg-gradient-to-r from-cyan-500 to-cyan-400 text-slate-950 font-semibold rounded-xl px-4 py-2 flex-1 transition-colors disabled:opacity-50">
                  {saving ? <span className="spinner w-5 h-5" /> : 'Cadastrar Membro'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
