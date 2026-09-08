<?php

namespace Tests\Feature\Api;

use App\Services\Blockchain\BlockchainPaymentService;
use App\Services\Blockchain\ProtocolConfigService;
use App\Services\Rescue\RescueProofService;
use App\Support\Base58;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RustLayoutCompatibilityTest extends TestCase
{
    public function test_laravel_decodes_actual_rust_serialized_states_byte_for_byte(): void
    {
        $key = fn (int $byte) => Base58::encode(str_repeat(chr($byte), 32));
        $fixtures = [];
        foreach (['trade' => 244, 'protocol' => 98, 'rescue' => 147] as $name => $size) {
            $fixtures[$name] = file_get_contents(base_path('tests/Fixtures/Solana/'.$name.'.bin'));
            $this->assertSame($size, strlen($fixtures[$name]));
        }
        Http::fake(function ($request) use ($fixtures, $key) {
            $address = $request->data()['params'][0];

            return Http::response(['result' => ['value' => [
                'owner' => $key(9), 'data' => [base64_encode($fixtures[$address]), 'base64'],
            ]]]);
        });
        $prepared = [
            'program_id' => $key(9), 'trade_id' => 42, 'mint' => $key(4), 'protocol_config' => ['pda' => $key(6)],
            'wallets' => ['buyer' => $key(1), 'producer' => $key(2), 'carrier' => $key(3)],
            'amounts' => ['product' => '100123456', 'shipping' => '12345678', 'protocol_fee' => '2002469', 'total' => '112469134'],
            'payment_expires_at' => gmdate('c', 2000000000),
        ];
        $trade = new \ReflectionMethod(BlockchainPaymentService::class, 'readAndValidateState');
        $this->assertSame(['version' => 2, 'tradeBump' => 254, 'vaultBump' => 253, 'status' => 1],
            $trade->invoke(app(BlockchainPaymentService::class), 'trade', $prepared, $key(5), 1));
        $protocol = new \ReflectionMethod(ProtocolConfigService::class, 'readState');
        $this->assertSame(['version' => 1, 'bump' => 250, 'authority_wallet' => $key(7), 'treasury_wallet' => $key(8), 'mint' => $key(4)],
            $protocol->invoke(app(ProtocolConfigService::class), 'protocol', $key(9)));
        $proof = new \ReflectionMethod(RescueProofService::class, 'readProof');
        $this->assertSame(['trade_id' => 42], $proof->invoke(app(RescueProofService::class), 'rescue', [
            'program_id' => $key(9), 'trade_id' => 42, 'wallets' => ['producer' => $key(2), 'ngo' => $key(1), 'carrier' => $key(3)],
            'metadata_hash' => str_repeat('0a', 32),
        ], 0));
    }
}
