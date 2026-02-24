import Dexie from 'dexie';

export const db = new Dexie('EnjoyFunDB');

db.version(1).stores({
  // Tabela local de produtos para acesso muito rápido
  products: 'id, event_id, name, price, stock_qty',
  
  // Tabela de sincronização (fila)
  offlineQueue: 'offline_id, status, payload_type' // uuid, pending|synced, sale|topup
});
