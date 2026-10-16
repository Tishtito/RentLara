<?php

namespace App\Http\Requests\Admin;

use App\Data\ConnectWhatsAppData;
use App\Models\WhatsAppConnection;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWhatsAppConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The token and secret can be left out when updating, to keep the stored ones.
        $required = WhatsAppConnection::query()->exists() ? 'nullable' : 'required';

        return [
            /**
             * "Phone number ID" from the Meta app (WhatsApp → API Setup).
             *
             * @example 106540352242922
             */
            'phone_number_id' => ['required', 'string', 'regex:/^\d+$/', 'max:64'],
            /**
             * "WhatsApp Business Account ID" from the same page.
             *
             * @example 102290129340398
             */
            'business_account_id' => ['required', 'string', 'regex:/^\d+$/', 'max:64'],
            /** A permanent System User access token with whatsapp_business_messaging permission. */
            'access_token' => [$required, 'string', 'max:1000'],
            /** The Meta app's App Secret (App settings → Basic); verifies delivery webhooks. */
            'app_secret' => [$required, 'string', 'max:255'],
            /**
             * Name of the approved bill template (default rentflow_bill_issued).
             *
             * @example rentflow_bill_issued
             */
            'template_name' => ['nullable', 'string', 'regex:/^[a-z0-9_]+$/', 'max:100'],
            /**
             * Template language code (default en).
             *
             * @example en
             */
            'template_language' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** A super admin must pick a landlord with X-Account-Id. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! app(CurrentAccount::class)->has()) {
                $validator->errors()->add('account', 'Choose an account with the X-Account-Id header.');
            }
        }];
    }

    public function toData(): ConnectWhatsAppData
    {
        return new ConnectWhatsAppData(
            accountId: app(CurrentAccount::class)->id(),
            phoneNumberId: $this->input('phone_number_id'),
            businessAccountId: $this->input('business_account_id'),
            accessToken: $this->input('access_token') ?: null,
            appSecret: $this->input('app_secret') ?: null,
            templateName: $this->input('template_name') ?: null,
            templateLanguage: $this->input('template_language') ?: null,
        );
    }
}
