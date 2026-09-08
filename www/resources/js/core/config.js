import { api } from './api.js';

export const config = {
    apiUrl: (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, ''),
    network: import.meta.env.VITE_SOLANA_NETWORK || 'devnet',
    programId: import.meta.env.VITE_SOLANA_PROGRAM_ID || 'Configure VITE_SOLANA_PROGRAM_ID',
    frusdMint: import.meta.env.VITE_FRUSD_MINT || 'Configure VITE_FRUSD_MINT',
    explorer: import.meta.env.VITE_SOLSCAN_URL || 'https://solscan.io',
};
