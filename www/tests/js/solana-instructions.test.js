import { describe, expect, it } from 'vitest';
import { createTokenAccountInstruction, derivedAddress, resolveAccounts, walletErrorMessage } from '../../resources/js/core/solana.js';

const PROGRAMA = 'Ex6CN32gBUH2JALUwbqyn8ZMwWmBNrrHa4sjDNMAm5sd';
const TOKEN_PROGRAM = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
const ATA_PROGRAM = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL';
const SYSTEM_PROGRAM = '11111111111111111111111111111111';
const COMPRADOR = '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM';
const PRODUTOR = 'ATB7z1UtmGPGgMbvvcMfLuXdBvtsfbD1k77A4yHymvap';

/** O mesmo formato que o `prepare` do Laravel devolve. */
const preparation = {
    program_id: PROGRAMA,
    mint: PROGRAMA,
    token_program_id: TOKEN_PROGRAM,
    wallets: { buyer: COMPRADOR, producer: PRODUTOR },
    pda_seeds: {
        trade: [
            { type: 'utf8', value: 'foodrescue_trade' },
            { type: 'base64', value: 'CgAAAAAAAAA=' },
            { type: 'pubkey', value: COMPRADOR },
        ],
    },
};

describe('montagem das instruções on-chain', () => {
    it('deriva o PDA do trade com o comprador na semente', async () => {
        const semComprador = {
            ...preparation,
            pda_seeds: { trade: preparation.pda_seeds.trade.slice(0, 2) },
        };

        const comComprador = await derivedAddress(preparation, 'trade_pda');

        expect(comComprador.toBase58()).not.toBe((await derivedAddress(semComprador, 'trade_pda')).toBase58());
    });

    it('recusa uma semente de tipo desconhecido em vez de adivinhar', async () => {
        const estranha = { ...preparation, pda_seeds: { trade: [{ type: 'hex', value: '0a' }] } };

        await expect(derivedAddress(estranha, 'trade_pda')).rejects.toThrow('Tipo de semente não suportado');
    });

    it('resolve a conta de token do produtor como conta associada da mint', async () => {
        const keys = await resolveAccounts(preparation, {
            data_base64: 'Aw==',
            accounts: [
                { name: 'buyer', pubkey: COMPRADOR, signer: true, writable: false },
                { name: 'trade_pda', derived: true, signer: false, writable: true },
                { name: 'producer_token_account', derived_by_frontend: true, signer: false, writable: true },
            ],
        });

        expect(keys[2].pubkey.toBase58()).toBe('8tfFjCfVhnbPernfmzMvYYTaDo8ig8rytgiz9poNM1Bn');
        // O dono vem de `wallets.producer`, deduzido do nome da conta.
        expect(keys[2].ata).toEqual({ owner: PRODUTOR, mint: PROGRAMA, tokenProgram: TOKEN_PROGRAM });
        expect(keys[0].ata).toBeNull();
    });

    it('a criação da conta de token é idempotente e paga por quem assina', async () => {
        const keys = await resolveAccounts(preparation, {
            data_base64: 'Aw==',
            accounts: [{ name: 'producer_token_account', derived_by_frontend: true, signer: false, writable: true }],
        });

        const instruction = await createTokenAccountInstruction(COMPRADOR, keys[0]);

        expect(instruction.programId.toBase58()).toBe(ATA_PROGRAM);
        expect([...instruction.data]).toEqual([1]);
        expect(instruction.keys.map((key) => key.pubkey.toBase58())).toEqual([
            COMPRADOR,
            keys[0].pubkey.toBase58(),
            PRODUTOR,
            PROGRAMA,
            SYSTEM_PROGRAM,
            TOKEN_PROGRAM,
        ]);
        expect(instruction.keys[0]).toMatchObject({ isSigner: true, isWritable: true });
        expect(instruction.keys[1]).toMatchObject({ isSigner: false, isWritable: true });
    });

    it('traduz erro inesperado da carteira em orientação sobre saldo', () => {
        expect(walletErrorMessage(new Error('Unexpected error'))).toContain('SOL para as taxas e FRUSD suficiente');
    });

    it('reconhece saldo insuficiente mesmo quando a carteira usa mensagem técnica', () => {
        expect(walletErrorMessage(new Error('Attempt to debit an account but found no record of a prior credit.'))).toBe(
            'Saldo insuficiente na carteira para esta transação. Verifique o SOL das taxas e o saldo em FRUSD.',
        );
    });
});
