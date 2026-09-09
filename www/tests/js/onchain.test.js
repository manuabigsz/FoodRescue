import { afterEach, describe, expect, it, vi } from 'vitest';
import { bootApp, click, sampleUser, trade, waitFor } from './helpers/boot.js';

const producer = { ...sampleUser, id: 7, roles: ['producer'] };
const buyer = { ...sampleUser, id: 8, roles: ['buyer'] };
const escrow = { trade_pda: '7BrDDUdFDsnEHhHcDd1nf8GghVBYCGbzK8znoKCjykHk', vault_token_account: 'CD2JoQYt8QK96ZASMf4YedNFzhNNqen7d3RtNRovefcU' };

const programId = 'Ex6CN32gBUH2JALUwbqyn8ZMwWmBNrrHa4sjDNMAm5sd';
const mint = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
const carteiraProdutor = sampleUser.solana_wallet_address;
const carteiraComprador = '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM';
const tesouraria = 'BPFLoaderUpgradeab1e11111111111111111111111';

/** A extensão de carteira não existe no jsdom; o teste injeta uma equivalente. */
function fingirCarteira(address = carteiraProdutor) {
    window.solana = {
        isPhantom: true,
        isConnected: true,
        publicKey: { toString: () => address },
        connect: vi.fn(async () => ({ publicKey: { toString: () => address } })),
        signAndSendTransaction: vi.fn(async () => ({ signature: 'assinatura-simulada' })),
    };
}

afterEach(() => {
    delete window.solana;
});

/** Seeds no formato que o backend envia: cada item diz o próprio tipo. */
function seeds(prefixo, comprador) {
    return [
        { type: 'utf8', value: prefixo },
        { type: 'base64', value: 'CgAAAAAAAAA=' },
        { type: 'pubkey', value: comprador },
    ];
}

function preparoEntrega(wallet = carteiraProdutor) {
    return {
        data: {
            cluster: 'devnet',
            rpc_url: 'https://api.devnet.solana.com',
            commitment: 'confirmed',
            program_id: programId,
            trade_id: 10,
            trade_pda: escrow.trade_pda,
            wallet,
            instruction: {
                data_base64: 'Bg==',
                accounts: [
                    { name: 'actor', pubkey: wallet, signer: true, writable: false },
                    { name: 'trade_pda', pubkey: escrow.trade_pda, signer: false, writable: true },
                ],
            },
        },
    };
}

function boot(user, overrides, routes = {}) {
    return bootApp({
        hash: '#/acompanhamento',
        session: { user },
        routes: { 'GET /auth/me': { data: user }, 'GET /trades': { data: [trade(overrides)] }, ...routes },
    });
}

describe('operação com custódia on-chain', () => {
    it('sem escrow o produtor confirma a coleta pela própria tela', async () => {
        await boot(producer, { status: 'funded', blockchain: null });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toContain('ready');
        expect(actions).not.toContain('onchain-ready');
    });

    it('com escrow a etapa passa pela assinatura on-chain', async () => {
        await boot(producer, { status: 'funded', blockchain: escrow });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toContain('onchain-ready');
        expect(actions).not.toContain('ready');
    });

    it('o painel busca a preparação do backend e oferece a assinatura na carteira', async () => {
        fingirCarteira();
        const { calls } = await boot(producer, { status: 'funded', blockchain: escrow }, {
            'POST /trades/10/delivery/ready-for-pickup/prepare': preparoEntrega(),
        });

        await click('[data-action="onchain-ready"]');
        const botao = await waitFor('[data-panel-confirm]');

        expect(calls.some((call) => call.path === '/trades/10/delivery/ready-for-pickup/prepare')).toBe(true);
        expect(document.querySelector('[data-action-panel]').textContent).toContain('lote está pronto');
        expect(botao.textContent).toContain('Confirmar liberação');
        expect(document.querySelector('[data-action-panel]').textContent).not.toContain('Programa');
        expect(document.querySelector('[data-action-panel]').textContent).not.toContain('Custódia');
    });

    it('sem carteira no navegador o painel explica o que falta em vez de oferecer o botão', async () => {
        await boot(producer, { status: 'funded', blockchain: escrow }, {
            'POST /trades/10/delivery/ready-for-pickup/prepare': preparoEntrega(),
        });

        await click('[data-action="onchain-ready"]');
        const painel = await waitFor('.action-panel .footer-note');

        expect(painel.textContent).toContain('Nenhuma carteira Solana');
        expect(painel.querySelector('[data-panel-confirm]')).toBeNull();
    });

    it('o pagamento monta as duas assinaturas do comprador a partir das seeds preparadas', async () => {
        fingirCarteira(carteiraComprador);
        await boot(buyer, { status: 'waiting_payment', blockchain: null }, {
            'POST /trades/10/blockchain/prepare': {
                data: {
                    preparation_version: 2,
                    cluster: 'devnet',
                    rpc_url: 'https://api.devnet.solana.com',
                    commitment: 'confirmed',
                    program_id: programId,
                    mint,
                    token_program_id: mint,
                    trade_id: 10,
                    wallets: { buyer: carteiraComprador, producer: carteiraProdutor, carrier: null },
                    pda_seeds: {
                        trade: seeds('foodrescue_trade', carteiraComprador),
                        vault: seeds('foodrescue_vault', carteiraComprador),
                    },
                    initialize_instruction: {
                        data_base64: 'AA==',
                        accounts: [
                            { name: 'buyer', pubkey: carteiraComprador, signer: true, writable: true },
                            { name: 'trade_pda', derived: true, signer: false, writable: true },
                            { name: 'vault_token_account', derived: true, signer: false, writable: true },
                            { name: 'buyer_token_account', derived_by_frontend: true, owner_wallet: carteiraComprador, mint, signer: false, writable: true },
                        ],
                    },
                    fund_instruction: { data_base64: 'AQ==', accounts: [] },
                },
            },
        });

        await click('[data-action="payment"]');

        expect((await waitFor('[data-panel-confirm]')).textContent).toContain('Assinar e pagar');
    });

    it('cancelamento com escrow financiado avisa que exige as duas assinaturas', async () => {
        fingirCarteira(carteiraComprador);
        await boot(buyer, { status: 'funded', blockchain: escrow }, {
            'POST /trades/10/blockchain/cancellation/prepare': {
                data: {
                    cluster: 'devnet',
                    rpc_url: 'https://api.devnet.solana.com',
                    commitment: 'confirmed',
                    program_id: programId,
                    mint,
                    token_program_id: mint,
                    trade_id: 10,
                    trade_pda: escrow.trade_pda,
                    vault_token_account: escrow.vault_token_account,
                    was_funded: true,
                    amounts: { refund: '1598000000' },
                    wallets: { actor: carteiraComprador, buyer: carteiraComprador, producer: carteiraProdutor, carrier: null },
                    cancel_instruction: {
                        data_base64: 'BA==',
                        accounts: [
                            { name: 'buyer', pubkey: carteiraComprador, signer: true, writable: false },
                            { name: 'producer', pubkey: carteiraProdutor, signer: true, writable: false },
                            { name: 'trade_pda', pubkey: escrow.trade_pda, signer: false, writable: true },
                        ],
                    },
                },
            },
        });

        await click('[data-action="cancel"]');
        const aviso = await waitFor('.action-panel .footer-note');

        expect(aviso.textContent).toContain('producer');
        expect(document.querySelector('[data-panel-confirm]')).toBeNull();
    });

    it('entregue com escrow oferece a liquidação ao destinatário', async () => {
        fingirCarteira(carteiraComprador);
        await boot(buyer, { status: 'delivered', blockchain: escrow }, {
            'POST /trades/10/blockchain/settlement/prepare': {
                data: {
                    cluster: 'devnet',
                    rpc_url: 'https://api.devnet.solana.com',
                    commitment: 'confirmed',
                    program_id: programId,
                    mint,
                    token_program_id: mint,
                    trade_id: 10,
                    trade_pda: escrow.trade_pda,
                    vault_token_account: escrow.vault_token_account,
                    wallets: { buyer: carteiraComprador, producer: carteiraProdutor, carrier: null, treasury: tesouraria },
                    settle_instruction: {
                        data_base64: 'Aw==',
                        accounts: [
                            { name: 'buyer', pubkey: carteiraComprador, signer: true, writable: false },
                            { name: 'trade_pda', pubkey: escrow.trade_pda, signer: false, writable: true },
                        ],
                    },
                },
            },
        });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toContain('onchain-settlement');

        await click('[data-action="onchain-settlement"]');

        expect((await waitFor('[data-panel-confirm]')).textContent).toContain('Finalizar e liberar valores');
        expect(document.querySelector('.settlement-intro').textContent).toContain('valores protegidos serão liberados');
        expect(document.querySelector('.settlement-intro').textContent).toContain('A entrega foi confirmada');
    });

    it('a instituição social abre o Proof of Rescue assinando sozinha', async () => {
        fingirCarteira(carteiraComprador);
        const ong = { ...buyer, roles: ['ngo'] };
        const { calls } = await boot(ong, { status: 'delivered', is_donation: true, blockchain: escrow, rescue_proof: null }, {
            'POST /trades/10/rescue-proof/prepare': {
                data: {
                    cluster: 'devnet',
                    rpc_url: 'https://api.devnet.solana.com',
                    commitment: 'confirmed',
                    program_id: programId,
                    trade_id: 10,
                    metadata_hash: 'a'.repeat(64),
                    wallets: { ngo: carteiraComprador, producer: carteiraProdutor, carrier: null },
                    pda_seeds: {
                        rescue: [
                            { type: 'utf8', value: 'foodrescue_rescue' },
                            { type: 'base64', value: 'CgAAAAAAAAA=' },
                            { type: 'pubkey', value: carteiraComprador },
                            { type: 'pubkey', value: carteiraProdutor },
                        ],
                    },
                    instruction: {
                        data_base64: 'BQ==',
                        accounts: [
                            { name: 'ngo', pubkey: carteiraComprador, signer: true, writable: true },
                            { name: 'producer', pubkey: carteiraProdutor, signer: false, writable: false },
                            { name: 'rescue_proof_pda', derived: true, signer: false, writable: true },
                        ],
                    },
                },
            },
        });

        await click('[data-action="proof"]');
        const botao = await waitFor('[data-panel-confirm]');

        expect(calls.some((call) => call.path === '/trades/10/rescue-proof/prepare')).toBe(true);
        expect(botao.textContent).toContain('Assinar a atestação');
    });

    it('o produtor confirma a atestação que a instituição social abriu', async () => {
        fingirCarteira(carteiraProdutor);
        const proofPda = '7BrDDUdFDsnEHhHcDd1nf8GghVBYCGbzK8znoKCjykHk';
        await boot(producer, {
            status: 'proof_pending',
            is_donation: true,
            blockchain: escrow,
            rescue_proof: { proof_pda: proofPda, awaiting_producer: true },
        }, {
            'POST /trades/10/rescue-proof/producer/prepare': {
                data: {
                    cluster: 'devnet',
                    rpc_url: 'https://api.devnet.solana.com',
                    commitment: 'confirmed',
                    program_id: programId,
                    trade_id: 10,
                    proof_pda: proofPda,
                    wallet: carteiraProdutor,
                    instruction: {
                        data_base64: 'CQ==',
                        accounts: [
                            { name: 'producer', pubkey: carteiraProdutor, signer: true, writable: false },
                            { name: 'rescue_proof_pda', pubkey: proofPda, signer: false, writable: true },
                        ],
                    },
                },
            },
        });

        await click('[data-action="proof-producer"]');

        expect((await waitFor('[data-panel-confirm]')).textContent).toContain('Assinar a confirmação');
    });

    it('a atestação já confirmada pelo produtor não oferece a etapa de novo', async () => {
        await boot(producer, {
            status: 'completed',
            is_donation: true,
            blockchain: escrow,
            rescue_proof: { proof_pda: escrow.trade_pda, awaiting_producer: false },
        });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).not.toContain('proof-producer');
    });

    it('as ações que não dependem da cadeia continuam disponíveis', async () => {
        await boot(buyer, { status: 'reserved', blockchain: null });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toEqual(expect.arrayContaining(['shipping', 'self-shipping', 'cancel']));
    });

    it('operação concluída mostra o PDA real no link do explorer', async () => {
        await boot(buyer, { status: 'completed', blockchain: escrow });

        const link = [...document.querySelectorAll('a')].find((a) => a.href.includes('solscan'));
        expect(link.href).toContain(escrow.trade_pda);
    });
});
