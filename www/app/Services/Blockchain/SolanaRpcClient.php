<?php

namespace App\Services\Blockchain;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SolanaRpcClient
{
    /** @return array<string, mixed> */
    public function call(string $method, array $params = []): array
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(12)
                ->retry(2, 150, throw: false)
                ->post((string) config('services.solana.rpc_url'), [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => $method,
                    'params' => $params,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Não foi possível consultar a Solana RPC.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Solana RPC retornou HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload) || isset($payload['error'])) {
            $message = is_array($payload['error'] ?? null)
                ? (string) ($payload['error']['message'] ?? 'erro desconhecido')
                : 'resposta inválida';
            throw new RuntimeException('Solana RPC: '.$message);
        }

        return $payload;
    }

    public function tokenDecimals(string $mint): int
    {
        $response = $this->call('getTokenSupply', [
            $mint,
            ['commitment' => (string) config('services.solana.commitment')],
        ]);

        $decimals = data_get($response, 'result.value.decimals');
        if (! is_int($decimals) || $decimals < 0 || $decimals > 18) {
            throw new RuntimeException('Não foi possível obter os decimais da mint configurada.');
        }

        return $decimals;
    }

    /** @return array<string, mixed> */
    public function signatureStatus(string $signature): array
    {
        $response = $this->call('getSignatureStatuses', [
            [$signature],
            ['searchTransactionHistory' => true],
        ]);
        $status = data_get($response, 'result.value.0');

        if (! is_array($status)) {
            throw new RuntimeException('A transação ainda não foi encontrada/confirmada na Solana.');
        }
        if (! array_key_exists('err', $status) || $status['err'] !== null) {
            throw new RuntimeException('A transação Solana falhou.');
        }

        $confirmation = $status['confirmationStatus'] ?? null;
        if (! in_array($confirmation, ['confirmed', 'finalized'], true)) {
            throw new RuntimeException('A transação Solana ainda não atingiu commitment confirmado.');
        }

        if (! is_int($status['slot'] ?? null) || $status['slot'] < 0) {
            throw new RuntimeException('Slot da transação Solana inválido.');
        }

        return $status;
    }

    /** @return array<string, mixed> */
    public function transaction(string $signature): array
    {
        $response = $this->call('getTransaction', [
            $signature,
            [
                'commitment' => (string) config('services.solana.commitment'),
                'maxSupportedTransactionVersion' => 0,
                'encoding' => 'jsonParsed',
            ],
        ]);
        $transaction = $response['result'] ?? null;

        if (! is_array($transaction)
            || ! is_array($transaction['meta'] ?? null)
            || ! array_key_exists('err', $transaction['meta'])
            || $transaction['meta']['err'] !== null
            || ! is_int($transaction['slot'] ?? null)
            || $transaction['slot'] < 0
            || ! is_array(data_get($transaction, 'transaction.message.instructions'))
            || ! is_array(data_get($transaction, 'transaction.message.accountKeys'))) {
            throw new RuntimeException('Não foi possível validar a transação Solana confirmada.');
        }

        return $transaction;
    }

    /** @return array<string, mixed> */
    public function accountInfo(string $address, string $encoding = 'base64'): array
    {
        $response = $this->call('getAccountInfo', [
            $address,
            [
                'commitment' => (string) config('services.solana.commitment'),
                'encoding' => $encoding,
            ],
        ]);
        $account = data_get($response, 'result.value');

        if (! is_array($account)) {
            throw new RuntimeException('Conta Solana não encontrada: '.$address);
        }

        return $account;
    }

    /** @param list<array<string, mixed>> $filters @return list<array<string, mixed>> */
    public function programAccounts(string $programId, array $filters): array
    {
        $response = $this->call('getProgramAccounts', [
            $programId,
            [
                'commitment' => (string) config('services.solana.commitment'),
                'encoding' => 'base64',
                'filters' => $filters,
            ],
        ]);
        $accounts = data_get($response, 'result');
        if (! is_array($accounts)) {
            throw new RuntimeException('A RPC não retornou a lista de contas do programa Solana.');
        }

        foreach ($accounts as $account) {
            if (! is_array($account) || ($account['pubkey'] ?? null) === null || ! is_array($account['account'] ?? null)) {
                throw new RuntimeException('A RPC retornou uma conta de programa inválida.');
            }
        }

        return $accounts;
    }
}
