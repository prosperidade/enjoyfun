/**
 * MemPalace Sidecar — Lightweight memory server for EnjoyFun.
 * BE-S6-A1: In-memory store with file persistence, REST API.
 * Rooms = isolated memory spaces per platform module.
 */

const express = require('express');
const cors = require('cors');
const { v4: uuidv4 } = require('uuid');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(cors());
app.use(express.json({ limit: '1mb' }));

const PORT = parseInt(process.env.MEMPALACE_PORT || '3100', 10);
const WING = process.env.MEMPALACE_WING || 'enjoyfun_hub';
const DATA_DIR = process.env.MEMPALACE_DATA_DIR || '/data';
const ROOMS = (process.env.MEMPALACE_ROOMS || '').split(',').filter(Boolean);

// In-memory store: { room_name: [{ id, content, metadata, created_at }] }
const store = {};
ROOMS.forEach(r => { store[r] = []; });

// Load persisted data
const dataFile = path.join(DATA_DIR, `${WING}.json`);
try {
  if (fs.existsSync(dataFile)) {
    const loaded = JSON.parse(fs.readFileSync(dataFile, 'utf-8'));
    Object.entries(loaded).forEach(([room, memories]) => {
      if (Array.isArray(memories)) store[room] = memories;
    });
    console.log(`[MemPalace] Loaded ${Object.values(store).flat().length} memories from ${dataFile}`);
  }
} catch (e) { console.warn('[MemPalace] Failed to load data:', e.message); }

// Persist periodically
const persist = () => {
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(dataFile, JSON.stringify(store, null, 2));
  } catch (e) { console.warn('[MemPalace] Persist failed:', e.message); }
};
setInterval(persist, 30000);

// Health
app.get('/health', (_, res) => res.json({ status: 'ok', wing: WING, rooms: ROOMS.length, memories: Object.values(store).flat().length }));

// List rooms
app.get('/rooms', (_, res) => res.json({ wing: WING, rooms: ROOMS.map(r => ({ name: r, count: (store[r] || []).length })) }));

// Store memory
app.post('/rooms/:room/memories', (req, res) => {
  const { room } = req.params;
  if (!store[room]) return res.status(404).json({ error: `Room '${room}' not found` });
  const memory = { id: uuidv4(), content: req.body.content || '', metadata: req.body.metadata || {}, created_at: new Date().toISOString() };
  store[room].push(memory);
  if (store[room].length > 1000) store[room] = store[room].slice(-1000); // cap per room
  res.status(201).json(memory);
});

// Search memories in a room
app.get('/rooms/:room/memories', (req, res) => {
  const { room } = req.params;
  if (!store[room]) return res.status(404).json({ error: `Room '${room}' not found` });
  const q = (req.query.q || '').toLowerCase();
  const limit = Math.min(parseInt(req.query.limit || '10', 10), 50);
  let results = store[room];
  if (q) results = results.filter(m => m.content.toLowerCase().includes(q));
  res.json({ room, total: results.length, memories: results.slice(-limit).reverse() });
});

// Recall across all rooms
app.get('/recall', (req, res) => {
  const q = (req.query.q || '').toLowerCase();
  const limit = Math.min(parseInt(req.query.limit || '5', 10), 20);
  const all = [];
  Object.entries(store).forEach(([room, memories]) => {
    memories.forEach(m => {
      if (!q || m.content.toLowerCase().includes(q)) {
        all.push({ ...m, room });
      }
    });
  });
  all.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  res.json({ query: q, total: all.length, memories: all.slice(0, limit) });
});

app.listen(PORT, () => console.log(`[MemPalace] wing=${WING} rooms=${ROOMS.length} port=${PORT}`));
process.on('SIGTERM', () => { persist(); process.exit(0); });
