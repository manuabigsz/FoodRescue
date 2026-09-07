import fs from 'node:fs';
import {
    Connection,
    Keypair,
    PublicKey,
    SystemProgram,
    Transaction,
    TransactionInstruction,
    sendAndConfirmTransaction,
} from '@solana/web3.js';

const rpc = process.env.SOLANA_RPC_URL ?? 'https://api.devnet.solana.com';
const programId = new PublicKey(process.env.PROGRAM_ID);
const mint = new PublicKey(process.env.MINT_ADDRESS);
const authorityPath = process.env.AUTHORITY_KEYPAIR;
const treasury = new PublicKey(process.env.TREASURY_ADDRESS);

if (!process.env.PROGRAM_ID || !process.env.MINT_ADDRESS || !authorityPath || !process.env.TREASURY_ADDRESS) {
    throw new Error('Informe PROGRAM_ID, MINT_ADDRESS, AUTHORITY_KEYPAIR e TREASURY_ADDRESS.');
}

const authority = Keypair.fromSecretKey(
    Uint8Array.from(JSON.parse(fs.readFileSync(authorityPath, 'utf8'))),
);

const [configPda, bump] = PublicKey.findProgramAddressSync(
    [Buffer.from('foodrescue_protocol'), authority.publicKey.toBuffer()],
    programId,
);

const connection = new Connection(rpc, 'confirmed');
const existing = await connection.getAccountInfo(configPda, 'confirmed');

console.log(`Program ID: ${programId.toBase58()}`);
console.log(`Authority: ${authority.publicKey.toBase58()}`);
console.log(`Treasury: ${treasury.toBase58()}`);
console.log(`Mint: ${mint.toBase58()}`);
console.log(`ProtocolConfig PDA: ${configPda.toBase58()}`);
console.log(`PDA bump: ${bump}`);

if (existing) {
    console.log('ProtocolConfig já existe; nenhuma transação foi enviada.');
    process.exit(0);
}

const instruction = new TransactionInstruction({
    programId,
    keys: [
        { pubkey: authority.publicKey, isSigner: true, isWritable: true },
        { pubkey: configPda, isSigner: false, isWritable: true },
        { pubkey: mint, isSigner: false, isWritable: false },
        { pubkey: SystemProgram.programId, isSigner: false, isWritable: false },
    ],
    data: Buffer.concat([Buffer.from([2]), treasury.toBuffer()]),
});

const signature = await sendAndConfirmTransaction(
    connection,
    new Transaction().add(instruction),
    [authority],
    { commitment: 'confirmed' },
);

console.log(`Signature: ${signature}`);
console.log(`ProtocolConfig PDA: ${configPda.toBase58()}`);
