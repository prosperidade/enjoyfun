import { useEffect, useState } from 'react';
import { Users as UsersIcon } from 'lucide-react';
import api from '../lib/api';
import toast from 'react-hot-toast';

export default function Users() {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/users').then(r => setUsers(r.data.data || [])).catch(() => toast.error('Erro ao carregar usuários.')).finally(() => setLoading(false));
  }, []);

  const toggleActive = async (id, current) => {
    try {
      await api.patch(`/users/${id}`, { is_active: current ? 0 : 1 });
      setUsers(u => u.map(user => user.id === id ? { ...user, is_active: !current } : user));
    } catch { toast.error('Erro ao atualizar.'); }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title flex items-center gap-2"><UsersIcon size={22} className="text-blue-400" /> Usuários</h1>
        <p className="text-gray-500 text-sm">{users.length} usuário(s) cadastrado(s)</p>
      </div>

      <div className="table-wrapper">
        <table className="table">
          <thead><tr><th>Nome</th><th>Email</th><th>Telefone</th><th>Status</th><th>Cadastrado em</th></tr></thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={5} className="text-center py-10"><div className="spinner w-6 h-6 mx-auto" /></td></tr>
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
                <td>{u.email}</td>
                <td>{u.phone || '—'}</td>
                <td>
                  <button onClick={() => toggleActive(u.id, u.is_active)} className={`badge ${u.is_active ? 'badge-green' : 'badge-red'} cursor-pointer hover:opacity-80`}>
                    {u.is_active ? 'Ativo' : 'Inativo'}
                  </button>
                </td>
                <td className="text-xs text-gray-400">{new Date(u.created_at).toLocaleDateString('pt-BR')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
