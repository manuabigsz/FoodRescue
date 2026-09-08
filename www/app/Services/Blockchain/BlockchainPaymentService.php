<?php

namespace App\Services\Blockchain;

use App\Enums\BlockchainTransactionStatus;
use App\Enums\BlockchainTransactionType;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\BlockchainTradeAccount;
use App\Models\BlockchainTransaction;
use App\Models\Trade;
use App\Models\User;
use App\Services\Marketplace\TradeCancellationService;
use App\Support\Base58;
use App\UserRole;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BlockchainPaymentService
{
    private const SYSTEM_PROGRAM = '11111111111111111111111111111111';

    private const TOKEN_PROGRAM = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

    private const TRADE_SEED = 'foodrescue_trade';

    private const VAULT_SEED = 'foodrescue_vault';

    /**
     * As seeds do PDA passaram a incluir a carteira do comprador, então uma
     * preparação gravada antes disso derivaria o endereço errado. A versão marca
     * o formato para que a preparação antiga seja refeita em vez de reaproveitada.
     */
    private const PREPARATION_VERSION = 2;

    private const STATE_SIZE = 244;

    private const STATE_INITIALIZED = 0;

    private const STATE_FUNDED = 1;

    private const STATE_SETTLED = 2;

    private const STATE_CANCELLED = 3;

    public const STATE_READY_FOR_PICKUP = 4;

    public const STATE_IN_TRANSIT = 5;

    public const STATE_DELIVERED = 6;

    public function __construct(
        private readonly SolanaRpcClient $rpc,
        private readonly ProtocolConfigService $protocol,
        private readonly TradeCancellationService $cancellations,
    ) {}

    /** @return array<string, mixed> */
    public function prepare(User $buyer, Trade $trade, bool $allowExpired = false): array
    {
        return DB::transaction(function () use ($buyer, $trade, $allowExpired): array {
            $locked = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            $this->assertBuyerCanPay($buyer, $locked, $allowExpired);
            $this->requiredWallet($buyer, 'pagador');
            $cached = $locked->blockchain_preparation;
            if ($cached !== null && ($cached['preparation_version'] ?? 1) === self::PREPARATION_VERSION) {
                return $cached;
            }
            $prepared = $this->buildPreparation($buyer, $locked);
            $locked->update(['blockchain_preparation' => $prepared]);

            return $prepared;
        }, 3);
    }

    private function buildPreparation(User $buyer, Trade $trade): array
    {
        $trade = $trade->loadMissing(['producer', 'shippingRequest.selectedOffer.carrier']);
        $this->assertBuyerCanPay($buyer, $trade);

        $protocol = $this->protocol->official();
        $programId = $protocol->program_id;
        $mint = $protocol->mint;
        $buyerWallet = $trade->blockchain_preparation['wallets']['buyer'] ?? $this->requiredWallet($buyer, $trade->is_donation ? 'instituição social' : 'comprador');
        $producerWallet = $trade->blockchain_preparation['wallets']['producer'] ?? $this->requiredWallet($trade->producer, 'produtor');
        $carrierWallet = $trade->shippingRequest?->selectedOffer?->carrier
            ? $this->requiredWallet($trade->shippingRequest->selectedOffer->carrier, 'transportadora')
            : null;

        try {
            $decimals = $this->rpc->tokenDecimals($mint);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }

        $product = $this->toBaseUnits((string) $trade->product_amount, $decimals);
        $shipping = $this->toBaseUnits((string) $trade->shipping_amount, $decimals);
        $fee = $this->toBaseUnits((string) $trade->protocol_fee, $decimals);
        $expectedFee = (string) BigInteger::of($product)->multipliedBy(200)->quotient(10000);
        abort_unless($fee === $expectedFee, 422, 'A precisão da mint é incompatível com a taxa registrada no trade.');
        $total = $this->addIntegers($product, $shipping);
        $expiresAt = $trade->payment_expires_at?->getTimestamp();
        abort_if($expiresAt === null, 409, 'O trade não possui prazo de pagamento definido.');

        $instruction = chr(0)
            .$this->packU64((string) $trade->id)
            .$this->packU64($product)
            .$this->packU64($shipping)
            .$this->packU64($fee)
            .$this->packU64((string) $expiresAt)
            .Base58::decode($producerWallet)
            .($carrierWallet ? Base58::decode($carrierWallet) : str_repeat("\0", 32));

        return [
            'preparation_version' => self::PREPARATION_VERSION,
            'cluster' => (string) config('services.solana.cluster'),
            'rpc_url' => (string) config('services.solana.rpc_url'),
            'commitment' => (string) config('services.solana.commitment'),
            'program_id' => $programId,
            'mint' => $mint,
            'token_decimals' => $decimals,
            'token_program_id' => self::TOKEN_PROGRAM,
            'protocol_config' => [
                'pda' => $protocol->config_pda,
                'authority_wallet' => $protocol->authority_wallet,
                'treasury_wallet' => $protocol->treasury_wallet,
            ],
            'system_program_id' => self::SYSTEM_PROGRAM,
            'trade_id' => $trade->id,
            'payment_expires_at' => $trade->payment_expires_at?->toISOString(),
            'amounts' => [
                'product' => $product,
                'shipping' => $shipping,
                'protocol_fee' => $fee,
                'total' => $total,
            ],
            'wallets' => [
                'buyer' => $buyerWallet,
                'producer' => $producerWallet,
                'carrier' => $carrierWallet,
                'protocol_authority' => $protocol->authority_wallet,
            ],
            'pda_seeds' => [
                'trade' => $this->pdaSeeds(self::TRADE_SEED, $trade->id, $buyerWallet),
                'vault' => $this->pdaSeeds(self::VAULT_SEED, $trade->id, $buyerWallet),
            ],
            'initialize_instruction' => [
                'data_base64' => base64_encode($instruction),
                'accounts' => [
                    ['name' => 'buyer', 'pubkey' => $buyerWallet, 'signer' => true, 'writable' => true],
                    ['name' => 'trade_pda', 'derived' => true, 'signer' => false, 'writable' => true],
                    ['name' => 'vault_token_account', 'derived' => true, 'signer' => false, 'writable' => true],
                    ['name' => 'buyer_token_account', 'derived_by_frontend' => true, 'owner_wallet' => $buyerWallet, 'mint' => $mint, 'signer' => false, 'writable' => true],
                    ['name' => 'protocol_config', 'pubkey' => $protocol->config_pda, 'signer' => false, 'writable' => false],
                    ['name' => 'mint', 'pubkey' => $mint, 'signer' => false, 'writable' => false],
                    ['name' => 'system_program', 'pubkey' => self::SYSTEM_PROGRAM, 'signer' => false, 'writable' => false],
                    ['name' => 'token_program', 'pubkey' => self::TOKEN_PROGRAM, 'signer' => false, 'writable' => false],
                ],
            ],
            'fund_instruction' => [
                'data_base64' => base64_encode(chr(1)),
                'accounts' => [
                    ['name' => 'buyer', 'pubkey' => $buyerWallet, 'signer' => true, 'writable' => false],
                    ['name' => 'trade_pda', 'derived' => true, 'signer' => false, 'writable' => true],
                    ['name' => 'buyer_token_account', 'derived_by_frontend' => true, 'signer' => false, 'writable' => true],
                    ['name' => 'vault_token_account', 'derived' => true, 'signer' => false, 'writable' => true],
                    ['name' => 'mint', 'pubkey' => $mint, 'signer' => false, 'writable' => false],
                    ['name' => 'token_program', 'pubkey' => self::TOKEN_PROGRAM, 'signer' => false, 'writable' => false],
                ],
            ],
        ];
    }

    /**
     * Seeds autodescritas para o front derivar o PDA sem replicar regra de
     * codificação: cada item diz o próprio tipo.
     *
     * @return list<array{type:string,value:string}>
     */
    private function pdaSeeds(string $prefix, int $tradeId, string $buyerWallet): array
    {
        return [
            ['type' => 'utf8', 'value' => $prefix],
            ['type' => 'base64', 'value' => base64_encode($this->packU64((string) $tradeId))],
            ['type' => 'pubkey', 'value' => $buyerWallet],
        ];
    }

    /** @param array{signature:string,trade_pda:string,vault_token_account:string} $data */
    public function confirmInitialization(User $buyer, Trade $trade, array $data): BlockchainTradeAccount
    {
        return DB::transaction(function () use ($buyer, $trade, $data): BlockchainTradeAccount {
            $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()->with(['producer', 'shippingRequest.selectedOffer.carrier'])->firstOrFail();
            $this->assertBuyerCanPay($buyer, $lockedTrade, true);
            abort_if($lockedTrade->blockchainAccount()->exists(), 409, 'O trade já foi inicializado on-chain.');

            $prepared = $this->prepare($buyer, $lockedTrade, true);
            $this->assertTransaction($data['signature'], $prepared['program_id'], $data['trade_pda'], 0, [$prepared['wallets']['buyer']]);
            $state = $this->readAndValidateState($data['trade_pda'], $prepared, $data['vault_token_account'], self::STATE_INITIALIZED);
            $this->validateVault($data['vault_token_account'], $data['trade_pda'], $prepared['mint'], '0');
            $status = $this->rpcStatus($data['signature']);

            $account = BlockchainTradeAccount::create([
                'trade_id' => $lockedTrade->id,
                'cluster' => $prepared['cluster'],
                'program_id' => $prepared['program_id'],
                'mint' => $prepared['mint'],
                'protocol_config_pda' => $prepared['protocol_config']['pda'],
                'token_decimals' => $prepared['token_decimals'],
                'trade_pda' => $data['trade_pda'],
                'vault_token_account' => $data['vault_token_account'],
                'initialized_at' => now(),
            ]);

            BlockchainTransaction::create([
                'trade_id' => $lockedTrade->id,
                'type' => BlockchainTransactionType::InitializeTrade,
                'signature' => $data['signature'],
                'slot' => $status['slot'],
                'status' => BlockchainTransactionStatus::from($status['confirmationStatus']),
                'confirmed_at' => now(),
                'metadata' => ['state_status' => $state['status']],
            ]);

            return $account->load('transactions');
        }, 3);
    }

    /** @param array{signature:string} $data */
    public function confirmFunding(User $buyer, Trade $trade, array $data): Trade
    {
        return DB::transaction(function () use ($buyer, $trade, $data): Trade {
            $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()->with(['producer', 'shippingRequest.selectedOffer.carrier', 'blockchainAccount'])->firstOrFail();
            $this->assertBuyerCanPay($buyer, $lockedTrade, true);
            $account = $lockedTrade->blockchainAccount;
            abort_if($account === null, 409, 'Inicialize o trade on-chain antes de confirmar o funding.');
            abort_if($account->funded_at !== null, 409, 'O trade já está financiado.');

            $prepared = $this->prepare($buyer, $lockedTrade, true);
            $this->assertTransaction($data['signature'], $account->program_id, $account->trade_pda, 1, [$prepared['wallets']['buyer']]);
            $this->readAndValidateState($account->trade_pda, $prepared, $account->vault_token_account, self::STATE_FUNDED);
            $this->validateVault($account->vault_token_account, $account->trade_pda, $account->mint, $prepared['amounts']['total']);
            $status = $this->rpcStatus($data['signature']);

            $account->update(['funded_at' => now()]);
            BlockchainTransaction::create([
                'trade_id' => $lockedTrade->id,
                'type' => BlockchainTransactionType::FundTrade,
                'signature' => $data['signature'],
                'slot' => $status['slot'],
                'status' => BlockchainTransactionStatus::from($status['confirmationStatus']),
                'confirmed_at' => now(),
                'metadata' => ['vault' => $account->vault_token_account],
            ]);

            $lockedTrade->update([
                'status' => TradeStatus::Funded,
                'payment_expires_at' => null,
            ]);

            return $lockedTrade->fresh()->load(['shippingRequest.selectedOffer.carrier.roles', 'blockchainAccount.transactions']);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function prepareSettlement(User $buyer, Trade $trade, bool $verifyOnChain = true): array
    {
        $trade = $trade->loadMissing(['buyer', 'producer', 'shippingRequest.selectedOffer.carrier', 'blockchainAccount']);
        $this->assertBuyerCanSettle($buyer, $trade);
        $account = $trade->blockchainAccount;
        abort_if($account === null || $account->funded_at === null, 409, 'O escrow ainda não está financiado on-chain.');
        abort_if($account->settled_at !== null, 409, 'O trade já foi liquidado on-chain.');

        $protocol = $this->protocol->official();
        abort_unless($protocol->program_id === $account->program_id, 409, 'O programa do trade diverge da configuração oficial atual.');
        abort_unless($protocol->mint === $account->mint, 409, 'A mint do trade diverge da configuração oficial atual.');
        abort_unless($protocol->config_pda === $account->protocol_config_pda, 409, 'O ProtocolConfig do trade diverge da configuração oficial atual.');

        $buyerWallet = $trade->blockchain_preparation['wallets']['buyer'] ?? $this->requiredWallet($buyer, $trade->is_donation ? 'instituição social' : 'comprador');
        $producerWallet = $trade->blockchain_preparation['wallets']['producer'] ?? $this->requiredWallet($trade->producer, 'produtor');
        $carrier = $trade->shippingRequest?->selectedOffer?->carrier;
        $carrierWallet = $trade->blockchain_preparation['wallets']['carrier'] ?? ($carrier ? $this->requiredWallet($carrier, 'transportadora') : null);

        $product = $this->toBaseUnits((string) $trade->product_amount, $account->token_decimals);
        $shipping = $this->toBaseUnits((string) $trade->shipping_amount, $account->token_decimals);
        $fee = $this->toBaseUnits((string) $trade->protocol_fee, $account->token_decimals);
        $producerAmount = $this->subtractIntegers($product, $fee);
        $total = $this->addIntegers($product, $shipping);

        $prepared = [
            'cluster' => $account->cluster,
            'rpc_url' => (string) config('services.solana.rpc_url'),
            'commitment' => (string) config('services.solana.commitment'),
            'program_id' => $account->program_id,
            'mint' => $account->mint,
            'token_decimals' => $account->token_decimals,
            'token_program_id' => self::TOKEN_PROGRAM,
            'trade_id' => $trade->id,
            'payment_expires_at' => $trade->blockchain_preparation['payment_expires_at'] ?? null,
            'protocol_config' => [
                'pda' => $account->protocol_config_pda,
                'authority_wallet' => $protocol->authority_wallet,
                'treasury_wallet' => $protocol->treasury_wallet,
            ],
            'amounts' => [
                'product' => $product,
                'shipping' => $shipping,
                'protocol_fee' => $fee,
                'producer' => $producerAmount,
                'treasury' => $fee,
                'carrier' => $shipping,
                'total' => $total,
            ],
            'wallets' => [
                'buyer' => $buyerWallet,
                'producer' => $producerWallet,
                'carrier' => $carrierWallet,
                'treasury' => $protocol->treasury_wallet,
            ],
            'trade_pda' => $account->trade_pda,
            'vault_token_account' => $account->vault_token_account,
        ];

        if ($verifyOnChain) {
            $this->readAndValidateState($account->trade_pda, $prepared, $account->vault_token_account, self::STATE_DELIVERED);
            $this->validateVault($account->vault_token_account, $account->trade_pda, $account->mint, $total);
        }

        $accounts = [
            ['name' => 'buyer', 'pubkey' => $buyerWallet, 'signer' => true, 'writable' => false],
            ['name' => 'trade_pda', 'pubkey' => $account->trade_pda, 'signer' => false, 'writable' => true],
            ['name' => 'vault_token_account', 'pubkey' => $account->vault_token_account, 'signer' => false, 'writable' => true],
            ['name' => 'buyer_token_account', 'derived_by_frontend' => true, 'owner_wallet' => $buyerWallet, 'mint' => $account->mint, 'signer' => false, 'writable' => true],
            ['name' => 'protocol_config', 'pubkey' => $account->protocol_config_pda, 'signer' => false, 'writable' => false],
            ['name' => 'producer_token_account', 'derived_by_frontend' => true, 'owner_wallet' => $producerWallet, 'mint' => $account->mint, 'signer' => false, 'writable' => true],
            ['name' => 'treasury_token_account', 'derived_by_frontend' => true, 'owner_wallet' => $protocol->treasury_wallet, 'mint' => $account->mint, 'signer' => false, 'writable' => true],
            ['name' => 'mint', 'pubkey' => $account->mint, 'signer' => false, 'writable' => false],
            ['name' => 'token_program', 'pubkey' => self::TOKEN_PROGRAM, 'signer' => false, 'writable' => false],
        ];
        if ($shipping !== '0') {
            $accounts[] = ['name' => 'carrier_token_account', 'derived_by_frontend' => true, 'owner_wallet' => $carrierWallet, 'mint' => $account->mint, 'signer' => false, 'writable' => true];
        }

        $prepared['settle_instruction'] = [
            'data_base64' => base64_encode(chr(3)),
            'accounts' => $accounts,
        ];

        return $prepared;
    }

    /** @param array{signature:string} $data */
    public function confirmSettlement(User $buyer, Trade $trade, array $data): Trade
    {
        return DB::transaction(function () use ($buyer, $trade, $data): Trade {
            $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()
                ->with(['producer', 'surplusLot', 'shippingRequest.selectedOffer.carrier', 'blockchainAccount'])
                ->firstOrFail();
            $this->assertBuyerCanSettle($buyer, $lockedTrade);
            $account = $lockedTrade->blockchainAccount;
            abort_if($account === null || $account->funded_at === null, 409, 'O escrow ainda não está financiado on-chain.');
            abort_if($account->settled_at !== null, 409, 'O trade já foi liquidado on-chain.');

            $prepared = $this->prepareSettlement($buyer, $lockedTrade, false);
            $verified = $this->assertTransaction($data['signature'], $account->program_id, $account->trade_pda, 3, [$prepared['wallets']['buyer']]);
            $this->readAndValidateState($account->trade_pda, $prepared, $account->vault_token_account, self::STATE_SETTLED);
            $this->validateVault($account->vault_token_account, $account->trade_pda, $account->mint, '0', true);
            $preSettlementBalance = $this->settlementPreBalance($verified['transaction'], $account);
            abort_if($this->compareIntegers($preSettlementBalance, $prepared['amounts']['total']) < 0, 422, 'O saldo anterior da vault é inferior ao valor esperado do escrow.');
            $buyerExcessRefund = $this->subtractIntegers($preSettlementBalance, $prepared['amounts']['total']);
            $status = $this->rpcStatus($data['signature']);

            $account->update(['settled_at' => now()]);
            BlockchainTransaction::create([
                'trade_id' => $lockedTrade->id,
                'type' => BlockchainTransactionType::SettleTrade,
                'signature' => $data['signature'],
                'slot' => $status['slot'],
                'status' => BlockchainTransactionStatus::from($status['confirmationStatus']),
                'confirmed_at' => now(),
                'metadata' => [
                    'producer_amount' => $prepared['amounts']['producer'],
                    'protocol_fee' => $prepared['amounts']['treasury'],
                    'shipping_amount' => $prepared['amounts']['carrier'],
                    'buyer_excess_refund' => $buyerExcessRefund,
                    'treasury_wallet' => $prepared['wallets']['treasury'],
                ],
            ]);

            $requiresRescueProof = $lockedTrade->is_donation && $prepared['amounts']['carrier'] !== '0';
            $lockedTrade->update($requiresRescueProof
                ? ['status' => TradeStatus::ProofPending, 'proof_pending_at' => now()]
                : ['status' => TradeStatus::Completed, 'completed_at' => now()]);
            if (! $requiresRescueProof) {
                $lockedTrade->surplusLot()->update([
                    'status' => $lockedTrade->is_donation ? SurplusStatus::Donated : SurplusStatus::Sold,
                ]);
            }

            return $lockedTrade->fresh()->load([
                'shippingRequest.selectedOffer.carrier.roles',
                'blockchainAccount.transactions',
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function prepareCancellation(User $actor, Trade $trade, bool $verifyOnChain = true): array
    {
        $trade = $trade->loadMissing(['buyer', 'producer', 'shippingRequest.selectedOffer.carrier', 'blockchainAccount']);
        $this->cancellations->assertParticipant($actor, $trade);
        abort_unless(in_array($trade->status, [TradeStatus::WaitingPayment, TradeStatus::Funded], true), 409, 'O trade só pode ser cancelado on-chain antes da coleta.');
        abort_if($trade->ready_for_pickup_at !== null, 409, 'O trade já entrou no processo de coleta.');

        $account = $trade->blockchainAccount;
        abort_if($account === null, 409, 'O trade ainda não foi inicializado on-chain.');
        abort_if($account->settled_at !== null, 409, 'O trade já foi liquidado.');
        abort_if($account->cancelled_at !== null, 409, 'O trade já foi cancelado on-chain.');

        $isFunded = $trade->status === TradeStatus::Funded;
        abort_if($isFunded && $account->funded_at === null, 409, 'O backend indica funding, mas a referência on-chain ainda não foi confirmada.');
        abort_if(! $isFunded && $account->funded_at !== null, 409, 'O escrow já está financiado; atualize o estado do trade antes de cancelar.');

        $protocol = $this->protocol->official();
        abort_unless($protocol->program_id === $account->program_id, 409, 'O programa do trade diverge da configuração oficial atual.');
        abort_unless($protocol->mint === $account->mint, 409, 'A mint do trade diverge da configuração oficial atual.');
        abort_unless($protocol->config_pda === $account->protocol_config_pda, 409, 'O ProtocolConfig do trade diverge da configuração oficial atual.');

        $this->requiredWallet($actor, 'ator');
        $actorWallet = $trade->blockchain_preparation['wallets'][$actor->id === $trade->buyer_id ? 'buyer' : 'producer'] ?? $actor->solana_wallet_address;
        $buyerWallet = $trade->blockchain_preparation['wallets']['buyer'] ?? $this->requiredWallet($trade->buyer, 'comprador');
        $producerWallet = $trade->blockchain_preparation['wallets']['producer'] ?? $this->requiredWallet($trade->producer, 'produtor');
        $carrier = $trade->shippingRequest?->selectedOffer?->carrier;
        $carrierWallet = $trade->blockchain_preparation['wallets']['carrier'] ?? ($carrier ? $this->requiredWallet($carrier, 'transportadora') : null);

        $product = $this->toBaseUnits((string) $trade->product_amount, $account->token_decimals);
        $shipping = $this->toBaseUnits((string) $trade->shipping_amount, $account->token_decimals);
        $fee = $this->toBaseUnits((string) $trade->protocol_fee, $account->token_decimals);
        $total = $this->addIntegers($product, $shipping);
        $refund = $isFunded ? $total : '0';

        $prepared = [
            'cluster' => $account->cluster,
            'rpc_url' => (string) config('services.solana.rpc_url'),
            'commitment' => (string) config('services.solana.commitment'),
            'program_id' => $account->program_id,
            'mint' => $account->mint,
            'token_decimals' => $account->token_decimals,
            'token_program_id' => self::TOKEN_PROGRAM,
            'trade_id' => $trade->id,
            'payment_expires_at' => $trade->blockchain_preparation['payment_expires_at'] ?? null,
            'protocol_config' => ['pda' => $account->protocol_config_pda],
            'amounts' => [
                'product' => $product,
                'shipping' => $shipping,
                'protocol_fee' => $fee,
                'total' => $total,
                'refund' => $refund,
            ],
            'wallets' => [
                'actor' => $actorWallet,
                'buyer' => $buyerWallet,
                'producer' => $producerWallet,
                'carrier' => $carrierWallet,
            ],
            'trade_pda' => $account->trade_pda,
            'vault_token_account' => $account->vault_token_account,
            'was_funded' => $isFunded,
            'required_signers' => $isFunded ? [$buyerWallet, $producerWallet] : [$actorWallet],
        ];

        if ($verifyOnChain) {
            $this->readAndValidateState(
                $account->trade_pda,
                $prepared,
                $account->vault_token_account,
                $isFunded ? self::STATE_FUNDED : self::STATE_INITIALIZED,
            );
            $prepared['amounts']['refund'] = $this->validateVault($account->vault_token_account, $account->trade_pda, $account->mint, $refund);
        }

        $prepared['cancel_instruction'] = [
            'data_base64' => base64_encode(chr(4)),
            'accounts' => [
                ['name' => 'buyer', 'pubkey' => $buyerWallet, 'signer' => $isFunded || $actor->id === $trade->buyer_id, 'writable' => false],
                ['name' => 'producer', 'pubkey' => $producerWallet, 'signer' => $isFunded || $actor->id === $trade->producer_id, 'writable' => false],
                ['name' => 'trade_pda', 'pubkey' => $account->trade_pda, 'signer' => false, 'writable' => true],
                ['name' => 'vault_token_account', 'pubkey' => $account->vault_token_account, 'signer' => false, 'writable' => true],
                ['name' => 'buyer_token_account', 'derived_by_frontend' => true, 'owner_wallet' => $buyerWallet, 'mint' => $account->mint, 'signer' => false, 'writable' => true],
                ['name' => 'mint', 'pubkey' => $account->mint, 'signer' => false, 'writable' => false],
                ['name' => 'token_program', 'pubkey' => self::TOKEN_PROGRAM, 'signer' => false, 'writable' => false],
            ],
        ];

        return $prepared;
    }

    /** @param array{signature:string,reason?:string|null} $data */
    public function confirmCancellation(User $actor, Trade $trade, array $data): Trade
    {
        return DB::transaction(function () use ($actor, $trade, $data): Trade {
            $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()
                ->with(['buyer', 'producer', 'surplusLot', 'shippingRequest.selectedOffer.carrier', 'blockchainAccount'])
                ->firstOrFail();

            $prepared = $this->prepareCancellation($actor, $lockedTrade, false);
            $account = $lockedTrade->blockchainAccount;
            abort_if($account === null, 409, 'O trade ainda não foi inicializado on-chain.');

            $verified = $this->assertTransaction($data['signature'], $account->program_id, $account->trade_pda, 4, $prepared['required_signers']);
            $this->readAndValidateState($account->trade_pda, $prepared, $account->vault_token_account, self::STATE_CANCELLED);
            $this->validateVault($account->vault_token_account, $account->trade_pda, $account->mint, '0', true);
            $status = $this->rpcStatus($data['signature']);

            $wasFunded = (bool) $prepared['was_funded'];
            $refund = $this->refundBalance($verified['transaction'], $account);
            abort_if($this->compareIntegers($refund, $prepared['amounts']['refund']) < 0, 422, 'O saldo anterior ao refund é insuficiente.');
            $account->update([
                'cancelled_at' => now(),
                'refunded_at' => $refund !== '0' ? now() : null,
            ]);

            BlockchainTransaction::create([
                'trade_id' => $lockedTrade->id,
                'type' => BlockchainTransactionType::CancelTrade,
                'signature' => $data['signature'],
                'slot' => $status['slot'],
                'status' => BlockchainTransactionStatus::from($status['confirmationStatus']),
                'confirmed_at' => now(),
                'metadata' => [
                    'cancelled_by_user_id' => $actor->id,
                    'cancelled_by_wallet' => $prepared['wallets']['actor'],
                    'refund_amount' => $refund,
                    'was_funded' => $wasFunded,
                ],
            ]);

            $this->cancellations->finalizeBackendCancellation(
                $actor,
                $lockedTrade,
                $data['reason'] ?? null,
            );

            return $lockedTrade->fresh()->load([
                'shippingRequest.selectedOffer.carrier.roles',
                'blockchainAccount.transactions',
            ]);
        }, 3);
    }

    private function assertBuyerCanPay(User $buyer, Trade $trade, bool $allowExpired = false): void
    {
        $this->assertPayerRole($buyer, $trade);
        abort_unless($trade->buyer_id === $buyer->id, 403);
        abort_unless($trade->status === TradeStatus::WaitingPayment, 409, 'O trade não está aguardando pagamento.');
        abort_if(! $allowExpired && $trade->payment_expires_at?->isPast(), 409, 'O prazo de pagamento expirou.');
    }

    private function assertBuyerCanSettle(User $buyer, Trade $trade): void
    {
        $this->assertPayerRole($buyer, $trade);
        abort_unless($trade->buyer_id === $buyer->id, 403);
        abort_unless($trade->status === TradeStatus::Delivered, 409, 'O trade precisa estar marcado como entregue antes do settlement.');
    }

    private function assertPayerRole(User $user, Trade $trade): void
    {
        $this->requiredWallet($user, 'pagador');
        if ($trade->is_donation) {
            abort_unless($user->hasRole(UserRole::Ngo->value), 403);

            return;
        }
        abort_unless($user->hasRole(UserRole::Buyer->value), 403);
    }

    private function requiredWallet(User $user, string $actor): string
    {
        abort_if(blank($user->solana_wallet_address), 422, 'A wallet Solana do '.$actor.' não foi cadastrada.');
        abort_if($user->solana_wallet_verified_at === null, 422, 'A wallet Solana do '.$actor.' ainda não foi verificada por assinatura.');

        return (string) $user->solana_wallet_address;
    }

    private function configuredAddress(string $key): string
    {
        $value = (string) config('services.solana.'.$key);
        abort_if($value === '', 503, 'Configuração SOLANA_'.strtoupper($key).' ausente.');
        try {
            abort_unless(strlen(Base58::decode($value)) === 32, 503, 'Configuração Solana inválida.');
        } catch (\Throwable) {
            abort(503, 'Configuração Solana inválida.');
        }

        return $value;
    }

    private function rpcStatus(string $signature): array
    {
        try {
            return $this->rpc->signatureStatus($signature);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
    }

    private function assertTransaction(string $signature, string $programId, string $tradePda, int $tag, array $signers): array
    {
        return app(SolanaTransactionVerifier::class)->verify($signature, $programId, $tradePda, $tag, $signers);
    }

    private function refundBalance(array $transaction, BlockchainTradeAccount $account): string
    {
        $keys = data_get($transaction, 'transaction.message.accountKeys', []);
        $index = collect($keys)->search(fn ($key) => (is_array($key) ? ($key['pubkey'] ?? null) : $key) === $account->vault_token_account);
        abort_if($index === false, 422, 'A transação não referencia a vault do refund.');
        $balance = collect(data_get($transaction, 'meta.preTokenBalances', []))->first(fn ($entry) => is_array($entry)
            && ($entry['accountIndex'] ?? null) === $index && ($entry['mint'] ?? null) === $account->mint
            && ($entry['owner'] ?? null) === $account->trade_pda);
        $amount = data_get($balance, 'uiTokenAmount.amount');
        abort_unless(is_string($amount) && preg_match('/^\d+$/', $amount), 422, 'Saldo anterior ao refund ausente ou inválido.');

        // Read the actual CPI amount: tokens may also arrive earlier in the
        // same transaction, so preTokenBalances alone can understate a refund.
        $instructions = data_get($transaction, 'transaction.message.instructions', []);
        $cancelIndex = collect($instructions)->search(fn ($instruction) => is_array($instruction)
            && ($instruction['programId'] ?? null) === $account->program_id
            && ($instruction['data'] ?? null) === Base58::encode(chr(4))
            && in_array($account->trade_pda, (array) ($instruction['accounts'] ?? []), true));
        $inner = data_get($transaction, 'meta.innerInstructions');
        abort_unless(is_array($inner), 422, 'Instruções internas do refund ausentes.');
        $group = collect($inner)->first(fn ($entry) => is_array($entry) && ($entry['index'] ?? null) === $cancelIndex);
        $refund = '0';
        foreach ((array) data_get($group, 'instructions', []) as $instruction) {
            if (! is_array($instruction) || ($instruction['programId'] ?? null) !== self::TOKEN_PROGRAM
                || data_get($instruction, 'parsed.type') !== 'transferChecked'
                || data_get($instruction, 'parsed.info.source') !== $account->vault_token_account
                || data_get($instruction, 'parsed.info.mint') !== $account->mint
                || data_get($instruction, 'parsed.info.authority') !== $account->trade_pda) {
                continue;
            }
            $transferred = data_get($instruction, 'parsed.info.tokenAmount.amount');
            abort_unless(is_string($transferred) && preg_match('/^\d+$/', $transferred), 422, 'Valor transferido no refund inválido.');
            $refund = $this->addIntegers($refund, $transferred);
        }
        abort_if($this->compareIntegers($refund, $amount) < 0, 422, 'A transferência não devolve o saldo anterior da vault.');

        return $refund;
    }

    private function settlementPreBalance(array $transaction, BlockchainTradeAccount $account): string
    {
        $keys = data_get($transaction, 'transaction.message.accountKeys', []);
        $index = collect($keys)->search(fn ($key) => (is_array($key) ? ($key['pubkey'] ?? null) : $key) === $account->vault_token_account);
        abort_if($index === false, 422, 'A transação não referencia a vault da liquidação.');
        $balance = collect(data_get($transaction, 'meta.preTokenBalances', []))->first(fn ($entry) => is_array($entry)
            && ($entry['accountIndex'] ?? null) === $index
            && ($entry['mint'] ?? null) === $account->mint
            && ($entry['owner'] ?? null) === $account->trade_pda);
        $amount = data_get($balance, 'uiTokenAmount.amount');
        abort_unless(is_string($amount) && preg_match('/^\d+$/', $amount), 422, 'Saldo anterior à liquidação ausente ou inválido.');

        return $amount;
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    private function readAndValidateState(string $tradePda, array $prepared, string $vault, int $expectedStatus): array
    {
        try {
            $account = $this->rpc->accountInfo($tradePda);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        abort_unless(($account['owner'] ?? null) === $prepared['program_id'], 422, 'O PDA informado não pertence ao programa FoodRescue.');

        $encoded = data_get($account, 'data.0');
        $binary = is_string($encoded) ? base64_decode($encoded, true) : false;
        abort_unless(is_string($binary) && strlen($binary) === self::STATE_SIZE, 422, 'Estado on-chain do trade inválido.');

        $offset = 0;
        $version = ord($binary[$offset++]);
        $tradeBump = ord($binary[$offset++]);
        $vaultBump = ord($binary[$offset++]);
        $tradeId = $this->unpackU64(substr($binary, $offset, 8));
        $offset += 8;
        $buyer = substr($binary, $offset, 32);
        $offset += 32;
        $producer = substr($binary, $offset, 32);
        $offset += 32;
        $carrier = substr($binary, $offset, 32);
        $offset += 32;
        $mint = substr($binary, $offset, 32);
        $offset += 32;
        $vaultBytes = substr($binary, $offset, 32);
        $offset += 32;
        $protocolConfig = substr($binary, $offset, 32);
        $offset += 32;
        $product = $this->unpackU64(substr($binary, $offset, 8));
        $offset += 8;
        $shipping = $this->unpackU64(substr($binary, $offset, 8));
        $offset += 8;
        $fee = $this->unpackU64(substr($binary, $offset, 8));
        $offset += 8;
        $total = $this->unpackU64(substr($binary, $offset, 8));
        $offset += 8;
        $expires = $this->unpackU64(substr($binary, $offset, 8));
        $offset += 8;
        $status = ord($binary[$offset]);

        abort_unless($version === 2, 422, 'Versão do estado on-chain incompatível.');
        abort_unless($tradeId === (string) $prepared['trade_id'], 422, 'O PDA pertence a outro trade.');
        abort_unless(hash_equals($buyer, Base58::decode($prepared['wallets']['buyer'])), 422, 'Buyer on-chain divergente.');
        abort_unless(hash_equals($producer, Base58::decode($prepared['wallets']['producer'])), 422, 'Producer on-chain divergente.');
        $expectedCarrier = $prepared['wallets']['carrier'] ? Base58::decode($prepared['wallets']['carrier']) : str_repeat("\0", 32);
        abort_unless(hash_equals($carrier, $expectedCarrier), 422, 'Carrier on-chain divergente.');
        abort_unless(hash_equals($mint, Base58::decode($prepared['mint'])), 422, 'Mint on-chain divergente.');
        abort_unless(hash_equals($vaultBytes, Base58::decode($vault)), 422, 'Vault on-chain divergente.');
        abort_unless(hash_equals($protocolConfig, Base58::decode($prepared['protocol_config']['pda'])), 422, 'ProtocolConfig on-chain divergente.');
        abort_unless($product === $prepared['amounts']['product'], 422, 'Valor do produto on-chain divergente.');
        abort_unless($shipping === $prepared['amounts']['shipping'], 422, 'Valor do frete on-chain divergente.');
        abort_unless($fee === $prepared['amounts']['protocol_fee'], 422, 'Taxa do protocolo on-chain divergente.');
        abort_unless($total === $prepared['amounts']['total'], 422, 'Total do escrow on-chain divergente.');
        if (! empty($prepared['payment_expires_at'])) {
            abort_unless($expires === (string) strtotime((string) $prepared['payment_expires_at']), 422, 'Prazo on-chain divergente.');
        }
        abort_unless($status === $expectedStatus, 422, 'Estado on-chain não corresponde à operação confirmada.');

        return compact('version', 'tradeBump', 'vaultBump', 'status');
    }

    /** @param array<string, mixed> $prepared @return array<string, mixed> */
    public function validateOnChainState(string $tradePda, array $prepared, string $vault, int $expectedStatus): array
    {
        return $this->readAndValidateState($tradePda, $prepared, $vault, $expectedStatus);
    }

    private function validateVault(string $vault, string $tradePda, string $mint, ?string $expectedAmount = null, bool $exact = false): string
    {
        try {
            $account = $this->rpc->accountInfo($vault, 'jsonParsed');
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        abort_unless(($account['owner'] ?? null) === self::TOKEN_PROGRAM, 422, 'A vault não pertence ao SPL Token Program.');
        abort_unless(data_get($account, 'data.parsed.info.owner') === $tradePda, 422, 'A autoridade da vault não é o PDA do trade.');
        abort_unless(data_get($account, 'data.parsed.info.mint') === $mint, 422, 'A mint da vault é diferente da configurada.');
        $amount = data_get($account, 'data.parsed.info.tokenAmount.amount');
        abort_unless(is_string($amount) && preg_match('/^\d+$/', $amount), 422, 'Saldo da vault inválido.');
        if ($expectedAmount !== null) {
            if ($exact) {
                abort_unless($this->compareIntegers($amount, $expectedAmount) === 0, 422, 'O saldo da vault diverge do valor esperado.');
            } else {
                abort_if($this->compareIntegers($amount, $expectedAmount) < 0, 422, 'O saldo da vault é inferior ao valor esperado do escrow.');
            }
        }

        return $amount;
    }

    private function toBaseUnits(string $amount, int $decimals): string
    {
        $amount = trim($amount);
        abort_unless(preg_match('/^\d+(?:\.\d+)?$/', $amount) === 1, 422, 'Valor monetário inválido.');
        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        abort_if(strlen(rtrim(substr($fraction, $decimals), '0')) > 0, 422, 'A mint não possui casas decimais suficientes para o valor do trade.');
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');
        $raw = ltrim($integer.$fraction, '0');

        return $raw === '' ? '0' : $raw;
    }

    private function packU64(string $value): string
    {
        abort_if($this->compareIntegers($value, (string) PHP_INT_MAX) > 0, 422, 'Valor excede o limite suportado pelo backend.');

        return pack('P', (int) $value);
    }

    private function unpackU64(string $bytes): string
    {
        $value = unpack('Pvalue', $bytes);

        return (string) ($value['value'] ?? 0);
    }

    private function addIntegers(string $a, string $b): string
    {
        $carry = 0;
        $result = '';
        $a = strrev($a);
        $b = strrev($b);
        $length = max(strlen($a), strlen($b));
        for ($i = 0; $i < $length; $i++) {
            $sum = (int) ($a[$i] ?? 0) + (int) ($b[$i] ?? 0) + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $result .= (string) $carry;
        }

        return strrev($result);
    }

    private function subtractIntegers(string $a, string $b): string
    {
        abort_if($this->compareIntegers($a, $b) < 0, 422, 'Subtração monetária inválida.');
        $a = strrev($a);
        $b = strrev($b);
        $borrow = 0;
        $result = '';
        for ($i = 0; $i < strlen($a); $i++) {
            $digit = (int) $a[$i] - (int) ($b[$i] ?? 0) - $borrow;
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result .= (string) $digit;
        }
        $result = ltrim(strrev($result), '0');

        return $result === '' ? '0' : $result;
    }

    private function compareIntegers(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';

        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }
}
