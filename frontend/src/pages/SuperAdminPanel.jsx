import React, { useState, useEffect, useCallback } from 'react';

export default function SuperAdminPanel() {
    const [organizers, setOrganizers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [message, setMessage] = useState({ type: '', text: '' });
    
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        password: ''
    });

    // CORREÇÃO: Buscando a chave exata que o AuthContext salva no login
    const token = localStorage.getItem('access_token');

    // useCallback garante que a função não mude a cada renderização
    const fetchOrganizers = useCallback(async () => {
        try {
            const response = await fetch('/api/superadmin/organizers', {
                headers: { 'Authorization': `Bearer ${token}` }
            });

            // Verificamos se a resposta é JSON antes de tentar o .json()
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                const data = await response.json();
                if (data.success) {
                    setOrganizers(data.data.organizers || []);
                }
            } else {
                // Se cair aqui, o PHP enviou um erro HTML
                const errorText = await response.text();
                console.error('Resposta inválida do servidor (HTML detectado):', errorText);
            }
        } catch (error) {
            console.error('Erro ao buscar organizadores:', error);
        } finally {
            setLoading(false);
        }
    }, [token]);

    useEffect(() => {
        if (token) {
            fetchOrganizers();
        } else {
            setLoading(false);
            console.error("Token de acesso não encontrado no localStorage.");
        }
    }, [fetchOrganizers, token]); 

    // Atualização de estado blindada
    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setIsSubmitting(true);
        setMessage({ type: '', text: '' });

        try {
            const response = await fetch('/api/superadmin/organizers', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify(formData)
            });
            
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                const data = await response.json();
                if (data.success) {
                    setMessage({ type: 'success', text: 'Organizador criado e isolado com sucesso!' });
                    setFormData({ name: '', email: '', password: '' });
                    fetchOrganizers(); 
                } else {
                    setMessage({ type: 'error', text: data.message || 'Erro ao criar organizador.' });
                }
            } else {
                const errorText = await response.text();
                console.error("Erro no servidor (HTML):", errorText);
                setMessage({ type: 'error', text: 'Erro interno no servidor (PHP disparou um aviso).' });
            }
        } catch (error) {
            console.error("Erro no envio:", error); 
            setMessage({ type: 'error', text: 'Erro de conexão com o servidor.' });
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <div className="p-6 max-w-6xl mx-auto">
            <div className="mb-8">
                <h1 className="text-3xl font-bold text-gray-800">Painel Super Admin</h1>
                <p className="text-gray-600">Gestão White Label: Crie e gerencie os donos de eventos (Tenants).</p>
            </div>

            {message.text && (
                <div className={`p-4 mb-6 rounded ${message.type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                    {message.text}
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                
                <div className="bg-white p-6 rounded-lg shadow-md md:col-span-1 h-fit">
                    <h2 className="text-xl font-semibold mb-4 text-gray-800">Novo Organizador</h2>
                    {/* autoComplete="off" para evitar conflito com credenciais do SuperAdmin */}
                    <form onSubmit={handleSubmit} className="space-y-4" autoComplete="off">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Nome da Empresa</label>
                            {/* CORREÇÃO VISUAL: Forçando cor e fundo para não sumir */}
                            <input 
                                type="text" 
                                name="name"
                                value={formData.name || ''}
                                onChange={handleInputChange}
                                required
                                autoComplete="new-name"
                                className="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 text-gray-900 bg-white"
                                style={{ color: '#111827' }}
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700">E-mail de Acesso</label>
                            <input 
                                type="email" 
                                name="email"
                                value={formData.email || ''}
                                onChange={handleInputChange}
                                required
                                autoComplete="new-email"
                                className="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 text-gray-900 bg-white"
                                style={{ color: '#111827' }}
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Senha Inicial</label>
                            <input 
                                type="password" 
                                name="password"
                                value={formData.password || ''}
                                onChange={handleInputChange}
                                required
                                minLength="6"
                                autoComplete="new-password"
                                className="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 text-gray-900 bg-white"
                                style={{ color: '#111827' }}
                            />
                        </div>
                        <button 
                            type="submit" 
                            disabled={isSubmitting}
                            className={`w-full text-white p-2 rounded font-semibold ${isSubmitting ? 'bg-gray-400' : 'bg-blue-600 hover:bg-blue-700'}`}
                        >
                            {isSubmitting ? 'Criando...' : 'Criar Organizador'}
                        </button>
                    </form>
                </div>

                <div className="bg-white p-6 rounded-lg shadow-md md:col-span-2">
                    <h2 className="text-xl font-semibold mb-4 text-gray-800">Organizadores Ativos</h2>
                    
                    {loading ? (
                        <p className="text-gray-500">Carregando dados...</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">E-mail</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {organizers.length > 0 ? (
                                        organizers.map((org) => (
                                            <tr key={org.id} className="hover:bg-gray-50">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">#{org.id}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{org.name}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{org.email}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {org.created_at ? new Date(org.created_at).toLocaleDateString('pt-BR') : '-'}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="4" className="px-6 py-4 text-center text-sm text-gray-500">
                                                Nenhum organizador cadastrado.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}