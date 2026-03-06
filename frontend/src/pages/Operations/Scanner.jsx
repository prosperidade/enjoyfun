import { useEffect, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { Camera, CheckCircle2, XCircle, MapPin } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../lib/api';

export default function Scanner() {
  const [scanResult, setScanResult] = useState(null);
  const [isScanning, setIsScanning] = useState(false);
  const [cameraError, setCameraError] = useState('');
  
  // O Staff escolhe onde ele está trabalhando
  const [operationMode, setOperationMode] = useState('portaria'); 
  
  const scannerRef = useRef(null);
  const qrCodeRegionId = "qr-reader";

  // Inicia a câmera
  const startScanner = async () => {
    setScanResult(null);
    setCameraError('');
    setIsScanning(true);

    try {
      if (!scannerRef.current) {
        scannerRef.current = new Html5Qrcode(qrCodeRegionId);
      }

      await scannerRef.current.start(
        { facingMode: "environment" }, // Força a câmera traseira no celular
        {
          fps: 10,
          qrbox: { width: 250, height: 250 },
          aspectRatio: 1.0,
        },
        async (decodedText) => {
          // Quando acha um QR Code, pausa a câmera imediatamente
          scannerRef.current.pause();
          handleScan(decodedText);
        },
        (errorMessage) => {
          // Ignora erros de "QR code não encontrado na tela", são normais enquanto foca
        }
      );
    } catch (err) {
      console.error(err);
      setCameraError('Não foi possível acessar a câmera. Verifique as permissões.');
      setIsScanning(false);
    }
  };

  // Para a câmera ao sair da tela
  useEffect(() => {
    return () => {
      if (scannerRef.current && scannerRef.current.isScanning) {
        scannerRef.current.stop().catch(console.error);
      }
    };
  }, []);

  // Processa a leitura com o Backend
  const handleScan = async (qrData) => {
    try {
      // Exemplo de envio: o backend precisa saber O QUE estamos lendo e ONDE estamos.
      const { data } = await api.post('/scanner/process', {
        token: qrData,
        mode: operationMode // 'portaria', 'bar', 'estacionamento', etc.
      });

      // Toca um áudio de sucesso (Bip) - Opcional, mas útil na operação real
      // new Audio('/beep.mp3').play().catch(() => {});

      setScanResult({
        success: true,
        message: data.message || 'Leitura Aprovada!',
        details: data.data
      });
      toast.success('Leitura aprovada!');

    } catch (err) {
      setScanResult({
        success: false,
        message: err.response?.data?.message || 'Erro na validação do QR Code.',
      });
      toast.error('Erro na leitura');
    }
  };

  // Reseta para ler o próximo
  const resetScanner = () => {
    setScanResult(null);
    if (scannerRef.current) {
      scannerRef.current.resume();
    } else {
      startScanner();
    }
  };

  return (
    <div className="max-w-md mx-auto space-y-6 pb-20">
      <div className="text-center">
        <h1 className="text-2xl font-bold text-white flex items-center justify-center gap-2">
          <Camera className="text-brand" /> Scanner PDV
        </h1>
        <p className="text-sm text-gray-400 mt-1">Aponte a câmera para o QR Code</p>
      </div>

      {/* Seletor de Setor do Staff */}
      <div className="card p-4 flex items-center gap-3">
        <MapPin className="text-gray-400" size={20} />
        <select 
          className="input w-full bg-gray-900"
          value={operationMode}
          onChange={(e) => setOperationMode(e.target.value)}
          disabled={isScanning && !scanResult}
        >
          <option value="portaria">🎟️ Portaria (Check-in)</option>
          <option value="bar">🍺 Bar / Bebidas</option>
          <option value="food">🍔 Alimentação</option>
          <option value="shop">🛍️ Loja de Produtos</option>
          <option value="parking">🚗 Estacionamento</option>
        </select>
      </div>

      {/* Área da Câmera */}
      <div className="relative rounded-2xl overflow-hidden bg-black border-2 border-gray-800 shadow-2xl aspect-square flex items-center justify-center">
        {!isScanning && !scanResult && (
          <button onClick={startScanner} className="btn-primary flex items-center gap-2">
            <Camera size={20} /> Ligar Câmera
          </button>
        )}
        
        {/* Div onde o html5-qrcode injeta o vídeo */}
        <div id={qrCodeRegionId} className={`w-full h-full ${scanResult ? 'hidden' : 'block'}`} />

        {cameraError && (
          <div className="absolute inset-0 bg-gray-900 flex items-center justify-center p-6 text-center text-red-400">
            {cameraError}
          </div>
        )}

        {/* Feedback Visual Gigante (Verde ou Vermelho) */}
        {scanResult && (
          <div className={`absolute inset-0 flex flex-col items-center justify-center p-6 text-center animate-in fade-in zoom-in duration-200 ${scanResult.success ? 'bg-green-600' : 'bg-red-600'}`}>
            {scanResult.success ? <CheckCircle2 size={80} className="text-white mb-4" /> : <XCircle size={80} className="text-white mb-4" />}
            
            <h2 className="text-3xl font-black text-white leading-tight mb-2">
              {scanResult.success ? 'APROVADO' : 'NEGADO'}
            </h2>
            <p className="text-white/90 font-medium text-lg">
              {scanResult.message}
            </p>

            {scanResult.details?.name && (
              <div className="mt-4 bg-black/20 rounded-xl p-3 w-full">
                <p className="text-white font-bold">{scanResult.details.name}</p>
                <p className="text-white/80 text-sm">{scanResult.details.info}</p>
              </div>
            )}

            <button onClick={resetScanner} className="mt-8 px-8 py-3 bg-white text-black font-bold rounded-full shadow-lg hover:bg-gray-200 active:scale-95 transition-all">
              Bipar Próximo
            </button>
          </div>
        )}
      </div>
    </div>
  );
}