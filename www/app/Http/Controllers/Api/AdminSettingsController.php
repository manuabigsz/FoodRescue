<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UpdateTimeoutSettingsRequest;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        abort_unless(request()->user()->can('settings.manage'), 403);

        return response()->json(['data' => $this->values()]);
    }

    public function update(UpdateTimeoutSettingsRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $data = $request->validated();

        DB::transaction(function () use ($data): void {
            foreach ($data as $key => $value) {
                PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
            }
        });

        return response()->json(['data' => $this->values()]);
    }

    /** @return array<string, int> */
    private function values(): array
    {
        return [
            PlatformSetting::SHIPPING_QUOTATION_TIMEOUT => PlatformSetting::integer(PlatformSetting::SHIPPING_QUOTATION_TIMEOUT, 240),
            PlatformSetting::PAYMENT_TIMEOUT => PlatformSetting::integer(PlatformSetting::PAYMENT_TIMEOUT, 15),
        ];
    }
}
