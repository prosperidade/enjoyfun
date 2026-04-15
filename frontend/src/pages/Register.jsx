import { useState } from "react";
import { Link } from "react-router-dom";
import api from "../lib/api";
import toast from "react-hot-toast";
import { UserPlus, Check, ArrowLeft } from "lucide-react";

export default function Register() {
  const [form, setForm] = useState({ name: "", email: "", password: "", phone: "", cpf: "" });
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    setLoading(true);
    try {
      const res = await api.post("/auth/register", form);
      if (res.data?.data?.status === "pending") {
        setSuccess(true);
      } else {
        toast.success("Cadastro realizado!");
        setSuccess(true);
      }
    } catch (err) {
      const data = err.response?.data;
      if (data?.errors) {
        setErrors(data.errors);
      }
      toast.error(data?.message || "Erro ao cadastrar.");
    } finally {
      setLoading(false);
    }
  };

  const inputCls = "w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent";

  if (success) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
        <div className="bg-gray-900 border border-gray-800 rounded-2xl p-8 max-w-md w-full text-center">
          <div className="w-16 h-16 bg-emerald-900/40 rounded-full flex items-center justify-center mx-auto mb-4">
            <Check className="w-8 h-8 text-emerald-400" />
          </div>
          <h2 className="text-xl font-bold text-white mb-2">Cadastro enviado!</h2>
          <p className="text-gray-400 text-sm mb-6">
            Sua solicitacao foi recebida e esta aguardando aprovacao do administrador.
            Voce recebera uma notificacao quando sua conta for ativada.
          </p>
          <Link
            to="/login"
            className="inline-flex items-center gap-2 text-purple-400 hover:text-purple-300 text-sm"
          >
            <ArrowLeft className="w-4 h-4" /> Voltar ao login
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-950 flex items-center justify-center p-4">
      <div className="bg-gray-900 border border-gray-800 rounded-2xl p-8 max-w-md w-full">
        <div className="flex items-center gap-3 mb-6">
          <div className="w-10 h-10 bg-purple-900/40 rounded-full flex items-center justify-center">
            <UserPlus className="w-5 h-5 text-purple-400" />
          </div>
          <div>
            <h1 className="text-xl font-bold text-white">Criar conta</h1>
            <p className="text-xs text-gray-500">Cadastre-se como organizador de eventos</p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="text-xs text-gray-400 block mb-1">Nome completo</label>
            <input className={inputCls} placeholder="Seu nome" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            {errors.name && <p className="text-red-400 text-xs mt-1">{errors.name}</p>}
          </div>
          <div>
            <label className="text-xs text-gray-400 block mb-1">Email</label>
            <input className={inputCls} type="email" placeholder="seu@email.com" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            {errors.email && <p className="text-red-400 text-xs mt-1">{errors.email}</p>}
          </div>
          <div>
            <label className="text-xs text-gray-400 block mb-1">Telefone</label>
            <input className={inputCls} placeholder="(11) 99999-9999" required value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            {errors.phone && <p className="text-red-400 text-xs mt-1">{errors.phone}</p>}
          </div>
          <div>
            <label className="text-xs text-gray-400 block mb-1">CPF / CNPJ</label>
            <input className={inputCls} placeholder="000.000.000-00" required value={form.cpf} onChange={(e) => setForm({ ...form, cpf: e.target.value })} />
            {errors.cpf && <p className="text-red-400 text-xs mt-1">{errors.cpf}</p>}
          </div>
          <div>
            <label className="text-xs text-gray-400 block mb-1">Senha</label>
            <input className={inputCls} type="password" placeholder="Minimo 8 caracteres" required minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
            {errors.password && <p className="text-red-400 text-xs mt-1">{errors.password}</p>}
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg text-sm disabled:opacity-50 transition-colors"
          >
            {loading ? "Enviando..." : "Solicitar cadastro"}
          </button>
        </form>

        <p className="text-center text-xs text-gray-500 mt-4">
          Ja tem conta?{" "}
          <Link to="/login" className="text-purple-400 hover:text-purple-300">
            Fazer login
          </Link>
        </p>
      </div>
    </div>
  );
}
