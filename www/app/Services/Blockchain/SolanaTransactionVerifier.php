<?php

namespace App\Services\Blockchain;

use App\Models\BlockchainTransaction;
use App\Models\RescueProof;
use App\Support\Base58;
use RuntimeException;
use Throwable;

class SolanaTransactionVerifier
{
    public function __construct(private readonly SolanaRpcClient $rpc) {}

    /** @param list<string> $signers @return array<string, mixed> */
    public function verify(string $signature, string $program, string $pda, int $tag, array $signers): array
    {
        abort_if(BlockchainTransaction::where('signature', $signature)->exists()
            || RescueProof::where('signature', $signature)->exists(), 409, 'Esta assinatura já foi utilizada.');

        try {
            $status = $this->rpc->signatureStatus($signature);
            $transaction = $this->rpc->transaction($signature);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }

        abort_unless($transaction['slot'] === $status['slot'], 422, 'Slots da transação divergentes.');
        $keys = data_get($transaction, 'transaction.message.accountKeys', []);
        foreach ($signers as $signer) {
            abort_unless(collect($keys)->contains(fn ($key) => is_array($key)
                && ($key['pubkey'] ?? null) === $signer && ($key['signer'] ?? false) === true),
                422, 'A transação não contém todas as assinaturas exigidas.');
        }

        $matched = collect(data_get($transaction, 'transaction.message.instructions', []))
            ->contains(function ($instruction) use ($program, $pda, $tag): bool {
                if (! is_array($instruction) || ($instruction['programId'] ?? null) !== $program
                    || ! is_array($instruction['accounts'] ?? null)
                    || ! in_array($pda, $instruction['accounts'] ?? [], true)
                    || ! is_string($instruction['data'] ?? null)) {
                    return false;
                }
                try {
                    $data = Base58::decode($instruction['data']);
                } catch (Throwable) {
                    return false;
                }

                $length = [0 => 105, 1 => 1, 2 => 33, 3 => 1, 4 => 1, 5 => 73, 6 => 1, 7 => 1, 8 => 1][$tag] ?? null;

                return $length !== null && strlen($data) === $length
                    && ord($data[0]) === $tag;
            });
        abort_unless($matched, 422, 'A transação não executa a instrução esperada sobre este PDA.');

        return $status + ['transaction' => $transaction];
    }
}
