<?php

namespace App\Http\Requests\Api;

use App\Rules\SolanaAddress;
use App\Rules\SolanaSignature;
use App\Support\PasswordRules;
use App\UserRole;
use Illuminate\Validation\Rule;

class RegisterRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $role = $this->input('role');

        $commonProfileKeys = [
            'producer_type', 'buyer_type', 'organization_name', 'farm_name', 'document_number',
            'phone', 'country', 'state', 'city', 'address_line', 'postal_code', 'company_name',
            'contact_name', 'service_regions', 'vehicle_types', 'max_capacity_kg',
            'registration_number', 'description',
        ];

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'solana_wallet_address' => ['required', new SolanaAddress, Rule::unique('users', 'solana_wallet_address')],
            'wallet_challenge_id' => ['required', 'integer', 'min:1'],
            'wallet_signature' => ['required', new SolanaSignature],
            'password' => PasswordRules::rules(),
            'password_confirmation' => ['required', 'string', 'min:6', 'max:72'],
            'role' => ['required', Rule::in(UserRole::publicValues())],
            'profile' => ['required', 'array:'.implode(',', $commonProfileKeys)],

            'profile.producer_type' => [Rule::requiredIf($role === UserRole::Producer->value), 'prohibited_unless:role,producer', Rule::in(['individual', 'company', 'cooperative'])],
            'profile.buyer_type' => [Rule::requiredIf($role === UserRole::Buyer->value), 'prohibited_unless:role,buyer', Rule::in(['individual', 'company'])],
            'profile.organization_name' => [
                Rule::requiredIf(fn (): bool => $role === UserRole::Ngo->value
                    || ($role === UserRole::Producer->value && in_array($this->input('profile.producer_type'), ['company', 'cooperative'], true))
                    || ($role === UserRole::Buyer->value && $this->input('profile.buyer_type') === 'company')),
                'nullable', 'string', 'max:160', 'prohibited_if:role,carrier',
            ],
            'profile.farm_name' => ['nullable', 'string', 'max:160', 'prohibited_unless:role,producer'],
            'profile.document_number' => [Rule::requiredIf(in_array($role, [UserRole::Producer->value, UserRole::Buyer->value, UserRole::Carrier->value], true)), 'nullable', 'string', 'max:40', 'prohibited_if:role,ngo'],
            'profile.phone' => ['required', 'string', 'max:30'],
            'profile.country' => ['required', 'string', 'max:80'],
            'profile.state' => ['required', 'string', 'max:100'],
            'profile.city' => ['required', 'string', 'max:120'],
            'profile.address_line' => ['required', 'string', 'max:255'],
            'profile.postal_code' => ['nullable', 'string', 'max:20'],

            'profile.company_name' => [Rule::requiredIf($role === UserRole::Carrier->value), 'nullable', 'string', 'max:160', 'prohibited_unless:role,carrier'],
            'profile.contact_name' => [Rule::requiredIf(in_array($role, [UserRole::Carrier->value, UserRole::Ngo->value], true)), 'nullable', 'string', 'max:120', 'prohibited_unless:role,carrier,ngo'],
            'profile.service_regions' => [Rule::requiredIf($role === UserRole::Carrier->value), 'nullable', 'array', 'min:1', 'max:50', 'prohibited_unless:role,carrier'],
            'profile.service_regions.*' => ['string', 'max:120'],
            'profile.vehicle_types' => ['nullable', 'array', 'max:20', 'prohibited_unless:role,carrier'],
            'profile.vehicle_types.*' => ['string', 'max:80'],
            'profile.max_capacity_kg' => ['nullable', 'numeric', 'gt:0', 'max:99999999999.999', 'prohibited_unless:role,carrier'],

            'profile.registration_number' => [Rule::requiredIf($role === UserRole::Ngo->value), 'nullable', 'string', 'max:60', 'prohibited_unless:role,ngo'],
            'profile.description' => ['nullable', 'string', 'max:2000', 'prohibited_unless:role,ngo'],
        ];
    }
}
