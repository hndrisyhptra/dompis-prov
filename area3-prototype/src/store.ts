import { useSyncExternalStore } from 'react';
import { type State, now } from './domain';
import { seed } from './seed';
export const STORAGE_KEY = 'its-area3-prototype-v1';
const listeners = new Set<() => void>();
export let persistenceError = '';
function load(): State { try { const value = localStorage.getItem(STORAGE_KEY); if (value) { const data = JSON.parse(value); if (data.schema === 1 && Array.isArray(data.projects) && Array.isArray(data.terms)) { data.generation ??= now(); return data; } } } catch { persistenceError = 'Penyimpanan browser tidak dapat dibaca. Gunakan ekspor cadangan di Pengaturan.'; } return seed(); }
let state = load();
function persist(next: State) { try { localStorage.setItem(STORAGE_KEY, JSON.stringify(next)); persistenceError = ''; } catch { persistenceError = 'Penyimpanan browser penuh/tidak tersedia. Unduh cadangan sebelum menutup halaman.'; } }
persist(state);
export function mutate<T>(fn: (draft: State) => T): T { const next = structuredClone(state); const result = fn(next); next.lastSaved = now(); persist(next); state = next; listeners.forEach(fn => fn()); return result; }
export function reset(scenario = 'overview') { const next = seed(scenario); persist(next); state = next; listeners.forEach(fn => fn()); }
export function useStore() { return useSyncExternalStore(cb => { listeners.add(cb); return () => listeners.delete(cb); }, () => state); }
export function saveDraft(key: string, value: unknown) { try { localStorage.setItem(`area3-draft-${key}`, JSON.stringify(value)); } catch { /* Main store reports storage availability. */ } }
export function readDraft<T>(key: string, fallback: T): T { try { const v = localStorage.getItem(`area3-draft-${key}`); return v ? JSON.parse(v) : fallback; } catch { return fallback; } }
export function clearDraft(key: string) { localStorage.removeItem(`area3-draft-${key}`); }
export function clearAllDrafts() { Object.keys(localStorage).filter(k => k.startsWith('area3-draft-')).forEach(k => localStorage.removeItem(k)); }
