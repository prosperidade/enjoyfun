import { useEffect, useState } from 'react';
import { Palette, Upload, Save } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../lib/api';

const BRAND_EVENT = 'brand-settings-updated';

function applyBrand(settings) {
  const root = document.documentElement;
  root.style.setProperty('--color-primary', settings.primary_color || '#7C3AED');
  root.style.setProperty('--color-secondary', settings.secondary_color || '#DB2777');
  root.style.setProperty('--brand-logo-url', settings.logo_url ? `url(${settings.logo_url})` : 'none');

  window.dispatchEvent(new CustomEvent(BRAND_EVENT, { detail: settings }));
  localStorage.setItem('enjoyfun_brand', JSON.stringify(settings));
}

export default function Settings() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [form, setForm] = useState({
    app_name: 'EnjoyFun',
    primary_color: '#7C3AED',
    secondary_color: '#DB2777',
    logo_url: '',
  });

  useEffect(() => {
    const bootstrap = async () => {
      try {
        const { data } = await api.get('/organizer-settings');
        const payload = data.data || {};
        const next = {
          app_name: payload.app_name || 'EnjoyFun',
          primary_color: payload.primary_color || '#7C3AED',
          secondary_color: payload.secondary_color || '#DB2777',
          logo_url: payload.logo_url || '',
        };
        setForm(next);
        applyBrand(next);
      } catch (err) {
        toast.error(err.response?.data?.message || 'Erro ao carregar configurações visuais.');
      } finally {
        setLoading(false);
      }
    };

    bootstrap();
  }, []);

  const onSave = async () => {
    setSaving(true);
    try {
      const payload = {
        app_name: form.app_name,
        primary_color: form.primary_color,
        secondary_color: form.secondary_color,
      };
      const { data } = await api.put('/organizer-settings', payload);
      const merged = { ...form, ...data.data };
      setForm(merged);
      applyBrand(merged);
      toast.success('Tema atualizado com sucesso.');
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao salvar tema.');
    } finally {
      setSaving(false);
    }
  };

  const onUploadLogo = async (file) => {
    if (!file) return;

    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('logo', file);

      const { data } = await api.post('/organizer-settings/logo', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      const merged = { ...form, logo_url: data.data?.logo_url || '' };
      setForm(merged);
      applyBrand(merged);
      toast.success('Logo enviada com sucesso.');
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erro ao enviar logo.');
    } finally {
      setUploading(false);
    }
  };

  if (loading) {
    return <div className="card">Carregando configurações...</div>;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title flex items-center gap-2">
          <Palette size={20} className="text-brand" /> White Label
        </h1>
        <p className="text-gray-400 text-sm mt-1">Personalize nome, cores e logo do organizador.</p>
      </div>

      <div className="card max-w-2xl space-y-5">
        <div>
          <label className="input-label">Nome do App</label>
          <input
            className="input"
            value={form.app_name}
            onChange={(e) => setForm((prev) => ({ ...prev, app_name: e.target.value }))}
            placeholder="Meu Evento"
          />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="input-label">Cor Primária</label>
            <input
              type="color"
              className="input h-12"
              value={form.primary_color}
              onChange={(e) => setForm((prev) => ({ ...prev, primary_color: e.target.value }))}
            />
          </div>
          <div>
            <label className="input-label">Cor Secundária</label>
            <input
              type="color"
              className="input h-12"
              value={form.secondary_color}
              onChange={(e) => setForm((prev) => ({ ...prev, secondary_color: e.target.value }))}
            />
          </div>
        </div>

        <div>
          <label className="input-label">Logo</label>
          <div className="flex items-center gap-4">
            <label className="btn-outline cursor-pointer">
              <Upload size={16} /> {uploading ? 'Enviando...' : 'Enviar arquivo'}
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                className="hidden"
                onChange={(e) => onUploadLogo(e.target.files?.[0])}
                disabled={uploading}
              />
            </label>
            {form.logo_url && (
              <img src={form.logo_url} alt="Logo atual" className="h-10 w-10 rounded-md object-cover border border-gray-700" />
            )}
          </div>
          {form.logo_url && <p className="text-xs text-gray-500 mt-2 break-all">{form.logo_url}</p>}
        </div>

        <div className="pt-2">
          <button className="btn-primary" onClick={onSave} disabled={saving}>
            <Save size={16} /> {saving ? 'Salvando...' : 'Salvar configurações'}
          </button>
        </div>
      </div>
    </div>
  );
}
