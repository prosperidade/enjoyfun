import { useEffect, useState } from 'react';
import { Users as UsersIcon, Plus, UserCheck, UserX, Shield, ChevronDown } from 'lucide-react';
import api from '../lib/api';
import toast from 'react-hot-toast';

const ROLE_LABELS = { manager: 'Gerente', cashier: 'Caixa' };
const SECTOR_LABELS = { bar: 'Bar', food: 'Alimentação', shop: 'Loja', all: 'Todos' };

const ROLE_COLORS = {
  manager: 'bg-purple-500/15 text-purple-400 border border-purple-500/30',
  cashier: 'bg-blue-500/15 text-blue-400 border border-blue-500/30',
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
          <h1 className="page-title flex items-center gap-2">
            <UsersIcon size={22} className="text-blue-400" /> Equipe / Staff
          </h1>
          <p className="text-gray-500 text-sm">{users.length} membro(s) cadastrado(s)</p>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="btn-primary flex items-center gap-2"
        >
          <Plus size={18} /> Novo Membro
        </button>
      </div>

      {/* Table */}
      <div className="table-wrapper">
        <table className="table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Email</th>
              <th>Telefone</th>
              <th>Cargo</th>
              <th>Setor</th>
              <th>Status</th>
              <th>Cadastrado em</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={7} className="text-center py-10"><div className="spinner w-6 h-6 mx-auto" /></td></tr>
            ) : users.length === 0 ? (
              <tr><td colSpan={7} className="text-center py-10 text-gray-500 text-sm">Nenhum membro cadastrado. Clique em "Novo Membro" para começar.</td></tr>
            ) : users.map(u => (
              <tr key={u.id}>
                <td>
                  <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-full bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center text-xs font-bold text-white flex-shrink-0">
                      {u.name?.charAt(0).toUpperCase()}
                    </div>
                    <span className="font-medium text-white">{u.name}</span>
                  </div>
                </td>
                <td className="text-gray-400">{u.email}</td>
                <td className="text-gray-400">{u.phone || '—'}</td>
                <td>
                  <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${ROLE_COLORS[u.role] || 'bg-gray-700 text-gray-300'}`}>
                    {ROLE_LABELS[u.role] || u.role || '—'}
                  </span>
                </td>
                <td>
                  <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${SECTOR_COLORS[u.sector] || 'bg-gray-700 text-gray-300'}`}>
                    {SECTOR_LABELS[u.sector] || u.sector || '—'}
                  </span>
                </td>
                <td>
                  <button
                    onClick={() => toggleActive(u.id, u.is_active)}
                    className={`flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full cursor-pointer hover:opacity-80 transition-opacity ${u.is_active ? 'bg-green-500/15 text-green-400 border border-green-500/30' : 'bg-red-500/15 text-red-400 border border-red-500/30'}`}
                  >
                    {u.is_active ? <UserCheck size={12} /> : <UserX size={12} />}
                    {u.is_active ? 'Ativo' : 'Inativo'}
                  </button>
                </td>
                <td className="text-xs text-gray-500">{new Date(u.created_at).toLocaleDateString('pt-BR')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Create User Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl">
            <div className="p-6 border-b border-gray-800 flex justify-between items-center">
              <h2 className="text-lg font-bold text-white flex items-center gap-2">
                <Shield size={20} className="text-blue-400" />
                Cadastrar Novo Membro da Equipe
              </h2>
              <button onClick={() => setShowModal(false)} className="text-gray-400 hover:text-white text-xl leading-none">✕</button>
            </div>

            <form onSubmit={handleCreate} className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="col-span-2">
                  <label className="block text-xs font-semibold text-gray-400 mb-1">Nome Completo *</label>
                  <input autoFocus className="input w-full" placeholder="Ex: João da Silva" {...field('name')} />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-400 mb-1">CPF</label>
                  <input className="input w-full" placeholder="000.000.000-00" {...field('cpf')} />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-400 mb-1">Telefone</label>
                  <input className="input w-full" placeholder="(11) 9 0000-0000" {...field('phone')} />
                </div>

                <div className="col-span-2">
                  <label className="block text-xs font-semibold text-gray-400 mb-1">E-mail *</label>
                  <input className="input w-full" type="email" placeholder="email@exemplo.com" {...field('email')} />
                </div>

                <div className="col-span-2">
                  <label className="block text-xs font-semibold text-gray-400 mb-1">Senha de Acesso *</label>
                  <input className="input w-full" type="password" placeholder="Mínimo 6 caracteres" {...field('password')} />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-400 mb-1">Cargo / Permissão</label>
                  <div className="relative">
                    <select className="input w-full pr-8 appearance-none" {...field('role')}>
                      <option value="cashier">Caixa</option>
                      <option value="manager">Gerente</option>
                    </select>
                    <ChevronDown size={14} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-400 mb-1">Setor de Atuação</label>
                  <div className="relative">
                    <select className="input w-full pr-8 appearance-none" {...field('sector')}>
                      <option value="all">Todos os Setores</option>
                      <option value="bar">Bar</option>
                      <option value="food">Alimentação</option>
                      <option value="shop">Loja</option>
                    </select>
                    <ChevronDown size={14} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
                  </div>
                </div>
              </div>

              <div className="pt-2 flex gap-3">
                <button type="button" onClick={() => setShowModal(false)} className="btn-secondary flex-1">
                  Cancelar
                </button>
                <button type="submit" disabled={saving} className="btn-primary flex-1">
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
