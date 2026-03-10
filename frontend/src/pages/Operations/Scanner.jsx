import { useEffect, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { Camera, CheckCircle2, XCircle, MapPin, Keyboard, AlertTriangle, Loader2, ArrowLeft } from 'lucide-react';
import toast from 'react-hot-toast';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import api from '../../lib/api';

const OPERATION_MODES = ['portaria', 'bar', 'food', 'shop', 'parking'];

export default function Scanner() {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const [scanResult, setScanResult] = useState(null);
  const [isScanning, setIsScanning] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [cameraError, setCameraError] = useState('');
  const requestedMode = searchParams.get('mode');
  const fixedMode = OPERATION_MODES.includes(requestedMode || '') ? requestedMode : '';
  const returnTo = location.state?.returnTo || (fixedMode === 'portaria' ? '/tickets' : '/');

  // O Staff escolhe onde ele está trabalhando (vazio para forçar a escolha)
  const [operationMode, setOperationMode] = useState(fixedMode || ''); 
  const [manualCode, setManualCode] = useState('');
  
  const scannerRef = useRef(null);
  const manualInputRef = useRef(null);
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
        () => {}
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

  useEffect(() => {
    if (fixedMode) {
      setOperationMode(fixedMode);
    }
  }, [fixedMode]);

  // Processa a leitura
  const handleScan = async (qrData) => {
    setIsProcessing(true);
    try {
      let data;

      if (operationMode === 'portaria') {
        try {
          const ticketResponse = await api.post('/tickets/validate', {
            dynamic_token: qrData,
          });

          data = {
            message: ticketResponse.data?.message || 'Acesso liberado!',
            data: {
              ...ticketResponse.data?.data,
              info: 'Ingresso validado',
            },
          };
        } catch (ticketErr) {
          if (ticketErr.response?.status !== 404) {
            throw ticketErr;
          }

          const fallbackResponse = await api.post('/scanner/process', {
            token: qrData,
            mode: operationMode
          });
          data = fallbackResponse.data;
        }
      } else {
        const response = await api.post('/scanner/process', {
          token: qrData,
          mode: operationMode
        });
        data = response.data;
      }

      setScanResult({
        type: 'success',
        message: data.message || 'Leitura Aprovada!',
        details: data.data
      });
      toast.success('Leitura aprovada!');

    } catch (err) {
      const msg = err.response?.data?.message || 'Erro na validação do QR Code.';
      const lowerMsg = msg.toLowerCase();
      
      let type = 'error';
      // Mapeia mensagens do backend para UX visual
      if (lowerMsg.includes('já utilizado') || lowerMsg.includes('já validado') || lowerMsg.includes('already used')) {
        type = 'warning';
      } else if (lowerMsg.includes('limite') || lowerMsg.includes('cota atingida')) {
        type = 'warning';
      } else if (lowerMsg.includes('bloqueado') || lowerMsg.includes('inapto')) {
        type = 'error';
      }

      setScanResult({
        type,
        message: msg,
      });
      toast.error(type === 'warning' ? 'Atenção na leitura' : 'Erro na leitura');
    } finally {
      setIsProcessing(false);
    }
  };

  useEffect(() => {
    if (!operationMode || scanResult || isProcessing) return;

    const keepManualFocus = () => {
      const activeTag = document.activeElement?.tagName?.toLowerCase();
      if (activeTag === 'button') return;

      if (manualInputRef.current && document.activeElement !== manualInputRef.current) {
        manualInputRef.current.focus();
      }
    };

    keepManualFocus();
    const focusInterval = setInterval(keepManualFocus, 500);
    return () => clearInterval(focusInterval);
  }, [isProcessing, operationMode, scanResult]);

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
    if (fixedMode) {
      return;
    }
    stopScanner();
    setOperationMode('');
    setScanResult(null);
    setManualCode('');
  };

  const handleExitScanner = async () => {
    stopScanner();
    navigate(returnTo);
  };

  return (
    <div className="max-w-md mx-auto space-y-6 pb-20">
      <div className="space-y-4">
        <button
          type="button"
          onClick={handleExitScanner}
          className="inline-flex items-center gap-2 rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-gray-700"
        >
          <ArrowLeft size={16} /> Voltar
        </button>

        <div className="text-center">
          <h1 className="text-2xl font-bold text-white flex items-center justify-center gap-2">
            <Camera className="text-brand" /> Scanner PDV
          </h1>
          <p className="text-sm text-gray-400 mt-1">Validação de Ingressos e Cartões</p>
        </div>
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
            {!fixedMode ? (
              <button onClick={handleChangeSector} className="bg-gray-800 hover:bg-gray-700 text-white text-sm py-1.5 px-4 rounded-lg transition-colors font-medium">
                 Trocar Setor
              </button>
            ) : null}
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

            {isProcessing && (
              <div className="absolute inset-0 bg-black/90 flex flex-col items-center justify-center p-6 text-center z-10 animate-fade-in backdrop-blur-sm">
                <Loader2 size={64} className="text-brand animate-spin mb-4" />
                <h2 className="text-2xl font-bold text-white tracking-widest uppercase">Processando</h2>
              </div>
            )}

            {scanResult && !isProcessing && (
              <div className={`absolute inset-0 flex flex-col items-center justify-center p-6 text-center animate-in fade-in zoom-in duration-200 z-10 
                ${scanResult.type === 'success' ? 'bg-green-600' : scanResult.type === 'warning' ? 'bg-amber-500' : 'bg-red-600'}`}>
                
                {scanResult.type === 'success' && <CheckCircle2 size={80} className="text-white mb-4 stroke-[2.5]" />}
                {scanResult.type === 'warning' && <AlertTriangle size={80} className="text-white mb-4 stroke-[2.5]" />}
                {scanResult.type === 'error' && <XCircle size={80} className="text-white mb-4 stroke-[2.5]" />}
                
                <h2 className="text-4xl font-black text-white leading-tight mb-2 tracking-wider uppercase drop-shadow-md">
                  {scanResult.type === 'success' ? 'APROVADO' : scanResult.type === 'warning' ? 'ATENÇÃO' : 'NEGADO'}
                </h2>
                <p className="text-white/95 font-semibold text-xl px-2 drop-shadow">
                  {scanResult.message}
                </p>

                {scanResult.details?.holder_name && (
                  <div className="mt-6 bg-black/20 rounded-xl p-4 w-full border border-white/20 backdrop-blur-sm self-stretch mx-4">
                    <p className="text-white font-black text-2xl truncate">{scanResult.details.holder_name}</p>
                    {scanResult.details?.info && <p className="text-white/90 font-medium mt-1 uppercase tracking-wider text-sm">{scanResult.details.info}</p>}
                  </div>
                )}

                <button onClick={resetScanner} className="mt-8 px-10 py-4 bg-white text-black font-black rounded-full shadow-[0_8px_30px_rgb(0,0,0,0.3)] hover:bg-gray-100 active:scale-95 transition-all w-full max-w-[280px] text-lg uppercase tracking-wider">
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
                ref={manualInputRef}
                type="text" 
                placeholder="Ex: EF-IMP-1234ABCD"
                className="input flex-1"
                value={manualCode}
                onChange={(e) => setManualCode(e.target.value)}
                disabled={!!scanResult}
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
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
