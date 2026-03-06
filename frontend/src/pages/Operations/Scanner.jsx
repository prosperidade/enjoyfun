import { useState } from 'react';
import { Camera, CheckCircle2, XCircle } from 'lucide-react';
import api from '../../lib/api';

export default function Scanner() {
  const [token, setToken] = useState('');
  const [mode, setMode] = useState('portaria');
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const handleProcess = async (e) => {
    e.preventDefault();
    setResult(null);
    setError('');

    if (!token.trim()) {
      setError('Informe um token de QR Code.');
      return;
    }

    setLoading(true);
    try {
      const { data } = await api.post('/scanner/process', {
        token: token.trim(),
        mode,
      });

      setResult(data.data || null);
      setToken('');
    } catch (err) {
      setError(err.response?.data?.message || 'Erro ao processar QR Code.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title flex items-center gap-2">
          <Camera size={22} className="text-blue-400" /> Scanner Operacional
        </h1>
        <p className="text-sm text-gray-500">Processamento de QR para portaria e operações.</p>
      </div>

      <div className="card p-5">
        <form className="space-y-4" onSubmit={handleProcess}>
          <div>
            <label className="block text-xs font-semibold text-gray-400 mb-1">Modo</label>
            <select className="input w-full" value={mode} onChange={(e) => setMode(e.target.value)}>
              <option value="portaria">Portaria</option>
              <option value="bar">Bar</option>
              <option value="parking">Parking</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-400 mb-1">Token do QR</label>
            <input
              className="input w-full"
              placeholder="Cole aqui o token lido"
              value={token}
              onChange={(e) => setToken(e.target.value)}
            />
          </div>

          <button className="btn-primary" type="submit" disabled={loading}>
            {loading ? 'Processando...' : 'Processar QR'}
          </button>
        </form>
      </div>

      {result && (
        <div className="rounded-2xl border border-green-500/30 bg-green-500/10 p-4">
          <p className="text-green-300 font-semibold flex items-center gap-2">
            <CheckCircle2 size={18} /> Acesso liberado
          </p>
          <p className="text-sm text-green-100 mt-2">Titular: {result.holder_name}</p>
          <p className="text-xs text-green-200/80 mt-1">Tipo: {result.source}</p>
        </div>
      )}

      {error && (
        <div className="rounded-2xl border border-red-500/30 bg-red-500/10 p-4">
          <p className="text-red-300 font-semibold flex items-center gap-2">
            <XCircle size={18} /> {error}
          </p>
        </div>
      )}
    </div>
  );
}
