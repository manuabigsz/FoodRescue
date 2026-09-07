<?php

namespace Tests\Feature\Api;

use App\Services\Blockchain\SolanaRpcClient;
use App\Services\Blockchain\SolanaTransactionVerifier;
use App\Support\Base58;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RpcValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function invalidRpc(): array
    {
        return [
            'null transaction' => ['transaction', ['result' => null]],
            'missing meta' => ['transaction', ['result' => ['slot' => 2]]],
            'missing error field' => ['transaction', ['result' => ['slot' => 2, 'meta' => []]]],
            'failed transaction' => ['transaction', ['result' => ['meta' => ['err' => ['InstructionError']]]]],
            'missing signature' => ['signatureStatus', ['result' => ['value' => [null]]]],
            'failed signature' => ['signatureStatus', ['result' => ['value' => [['err' => ['InstructionError'], 'slot' => 2, 'confirmationStatus' => 'confirmed']]]]],
            'processed signature' => ['signatureStatus', ['result' => ['value' => [['err' => null, 'slot' => 2, 'confirmationStatus' => 'processed']]]]],
            'missing signature error' => ['signatureStatus', ['result' => ['value' => [['slot' => 2, 'confirmationStatus' => 'confirmed']]]]],
            'missing slot' => ['signatureStatus', ['result' => ['value' => [['err' => null, 'confirmationStatus' => 'confirmed']]]]],
            'negative decimals' => ['tokenDecimals', ['result' => ['value' => ['decimals' => -1]]]],
            'huge decimals' => ['tokenDecimals', ['result' => ['value' => ['decimals' => 255]]]],
            'missing account' => ['accountInfo', ['result' => ['value' => null]]],
            'malformed' => ['transaction', 'not json'],
            'RPC error' => ['transaction', ['error' => ['message' => 'not found']]],
        ];
    }

    #[DataProvider('invalidRpc')]
    public function test_rpc_rejects_missing_or_malformed_evidence(string $method, mixed $payload): void
    {
        Http::fake(['*' => Http::response($payload)]);
        $this->expectException(RuntimeException::class);
        app(SolanaRpcClient::class)->{$method}('test');
    }

    public function test_timeout_is_reported_as_an_rpc_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível consultar');
        app(SolanaRpcClient::class)->transaction('test');
    }

    public static function wrongTransactions(): array
    {
        return [['program'], ['PDA'], ['instruction'], ['missing signer'], ['slot'], ['malformed instruction'], ['malformed accounts']];
    }

    #[DataProvider('wrongTransactions')]
    public function test_program_invocation_alone_cannot_confirm_a_different_operation(string $scenario): void
    {
        $instruction = ['programId' => 'program', 'accounts' => ['PDA'], 'data' => Base58::encode(chr(1))];
        $keys = [['pubkey' => 'buyer', 'signer' => true], ['pubkey' => 'PDA']];
        if ($scenario === 'program') {
            $instruction['programId'] = 'other';
        }
        if ($scenario === 'PDA') {
            $instruction['accounts'] = ['other'];
        }
        if ($scenario === 'instruction') {
            $instruction['data'] = Base58::encode(chr(3));
        }
        if ($scenario === 'missing signer') {
            $keys[0]['signer'] = false;
        }
        if ($scenario === 'malformed accounts') {
            $instruction['accounts'] = 'not an array';
        }
        if ($scenario === 'malformed instruction') {
            $instruction['data'] = '0invalid';
        }
        Http::fake(fn ($request) => Http::response(['result' => $request->data()['method'] === 'getSignatureStatuses'
            ? ['value' => [['slot' => 100, 'err' => null, 'confirmationStatus' => 'confirmed']]]
            : ['slot' => $scenario === 'slot' ? 101 : 100, 'meta' => ['err' => null], 'transaction' => ['message' => ['instructions' => [$instruction], 'accountKeys' => $keys]]]]));
        try {
            app(SolanaTransactionVerifier::class)->verify('signature', 'program', 'PDA', 1, ['buyer']);
            $this->fail('Invalid evidence was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }
}
