import { useEffect, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { Camera, CheckCircle2, XCircle, MapPin, Keyboard } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '../../lib/api';

export default function Scanner() {
  const [scanResult, setScanResult] = useState(null);
  const [isScanning, setIsScanning] = useState(false);
  const [cameraError, setCameraError] = useState('');
  
  // O Staff escolhe onde ele está trabalhando (vazio para forçar a escolha)
  const [operationMode, setOperationMode] = useState(''); 
  const [manualCode, setManualCode] = useState('');
  
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
        { facingMode: "environment" },
        {
          fps: 10,
          qrbox: { width: 250, height: 250 },
          aspectRatio: 1.0,
        },
        async (decodedText) => {
          scannerRef.current.pause();
          handleScan(decodedText);
        },
        (errorMessage) => {}
      );
    } catch (err) {
      console.error(err);
      setCameraError('Não foi possível acessar a câmera. Verifique as permissões.');
      setIsScanning(false);
    }
  };

  // Para a câmera
  const stopScanner = () => {
    if (scannerRef.current && scannerRef.current.isScanning) {
      scannerRef.current.stop().catch(console.error);
    }
    setIsScanning(false);
  };

  // Garante limpeza ao desmontar
  useEffect(() => {
    return stopScanner;
  }, []);

  // Processa a leitura
  const handleScan = async (qrData) => {
    try {
      const { data } = await api.post('/scanner/process', {
        token: qrData,
        mode: operationMode
      });

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

  // Submissão manual
  const handleManualSubmit = (e) => {
    e.preventDefault();
    if (manualCode.trim()) {
      handleScan(manualCode.trim());
      setManualCode('');
    }
  };

  // Reset para ler o próximo
  const resetScanner = () => {
    setScanResult(null);
    if (scannerRef.current && isScanning) {
      scannerRef.current.resume();
    } else {
      startScanner();
    }
  };

  // Seleciona um setor e habilita a câmera
  const handleSelectMode = (mode) => {
    setOperationMode(mode);
  };

  // Trocar de setor reseta a câmera e o estado do scanner
  const handleChangeSector = () => {
    stopScanner();
    setOperationMode('');
    setScanResult(null);
    setManualCode('');
  };

  return (
    <div className="max-w-md mx-auto space-y-6 pb-20">
      <div className="text-center">
        <h1 className="text-2xl font-bold text-white flex items-center justify-center gap-2">
          <Camera className="text-brand" /> Scanner PDV
        </h1>
        <p className="text-sm text-gray-400 mt-1">Validação de Ingressos e Cartões</p>
      </div>

      {!operationMode ? (
        <div className="card p-6 space-y-4">
          <h2 className="text-lg font-semibold text-white text-center">Selecione o Setor</h2>
          <div className="grid gap-3">
            <button onClick={() => handleSelectMode('portaria')} className="btn-secondary justify-start"><MapPin size={18}/> Portaria (Check-in)</button>
            <button onClick={() => handleSelectMode('bar')} className="btn-secondary justify-start"><MapPin size={18}/> Bar / Bebidas</button>
            <button onClick={() => handleSelectMode('food')} className="btn-secondary justify-start"><MapPin size={18}/> Alimentação</button>
            <button onClick={() => handleSelectMode('shop')} className="btn-secondary justify-start"><MapPin size={18}/> Loja de Produtos</button>
            <button onClick={() => handleSelectMode('parking')} className="btn-secondary justify-start"><MapPin size={18}/> Estacionamento</button>
          </div>
        </div>
      ) : (
        <>
          <div className="card p-4 flex items-center justify-between">
            <div className="flex items-center gap-3">
              <MapPin className="text-brand" size={20} />
              <span className="font-semibold text-white capitalize">
                Setor: {operationMode}
              </span>
            </div>
            <button onClick={handleChangeSector} className="bg-gray-800 hover:bg-gray-700 text-white text-sm py-1.5 px-4 rounded-lg transition-colors font-medium">
               Trocar Setor
            </button>
          </div>

          <div className="relative rounded-2xl overflow-hidden bg-black border-2 border-gray-800 shadow-2xl aspect-square flex items-center justify-center">
            {!isScanning && !scanResult && (
              <button onClick={startScanner} className="btn-primary flex items-center gap-2">
                <Camera size={20} /> Ligar Câmera
              </button>
            )}
            
            <div id={qrCodeRegionId} className={`w-full h-full ${scanResult ? 'hidden' : 'block'}`} />

            {cameraError && (
              <div className="absolute inset-0 bg-gray-900 flex items-center justify-center p-6 text-center text-red-400">
                {cameraError}
              </div>
            )}

            {scanResult && (
              <div className={`absolute inset-0 flex flex-col items-center justify-center p-6 text-center animate-in fade-in zoom-in duration-200 ${scanResult.success ? 'bg-green-600' : 'bg-red-600'}`}>
                {scanResult.success ? <CheckCircle2 size={80} className="text-white mb-4" /> : <XCircle size={80} className="text-white mb-4" />}
                
                <h2 className="text-3xl font-black text-white leading-tight mb-2">
                  {scanResult.success ? 'APROVADO' : 'NEGADO'}
                </h2>
                <p className="text-white/90 font-medium text-lg">
                  {scanResult.message}
                </p>

                {scanResult.details?.holder_name && (
                  <div className="mt-4 bg-black/20 rounded-xl p-3 w-full">
                    <p className="text-white font-bold">{scanResult.details.holder_name}</p>
                    {scanResult.details?.info && <p className="text-white/80 text-sm">{scanResult.details.info}</p>}
                  </div>
                )}

                <button onClick={resetScanner} className="mt-8 px-8 py-3 bg-white text-black font-bold rounded-full shadow-lg hover:bg-gray-200 active:scale-95 transition-all">
                  Ler Próximo
                </button>
              </div>
            )}
          </div>

          <form onSubmit={handleManualSubmit} className="card p-4 space-y-3">
            <h3 className="text-sm font-semibold text-gray-400 flex items-center gap-2">
              <Keyboard size={16} /> Digitar Código Manualmente
            </h3>
            <div className="flex gap-2">
              <input 
                type="text" 
                placeholder="Ex: EF-IMP-1234ABCD"
                className="input flex-1"
                value={manualCode}
                onChange={(e) => setManualCode(e.target.value)}
                disabled={!!scanResult}
              />
              <button type="submit" className="btn-primary whitespace-nowrap" disabled={!manualCode.trim() || !!scanResult}>
                Validar
              </button>
            </div>
          </form>
        </>
      )}
    </div>
  );
}