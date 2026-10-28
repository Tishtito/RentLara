<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API (Meta)
|--------------------------------------------------------------------------
|
| Bills are sent from each landlord's own WhatsApp Business number. Their
| credentials live in the whatsapp_connections table; these are the shared
| settings. Setup guide: docs/WHATSAPP.md.
|
*/

return [

    // Graph API version. Use the latest version shown in your Meta app dashboard.
    'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),

    'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),

    // Seconds to wait for Meta before giving up (the queue retries).
    'timeout' => (int) env('WHATSAPP_TIMEOUT', 20),

    // The approved message template used for bills (each landlord creates it in WhatsApp Manager).
    'bill_template' => [
        'name' => env('WHATSAPP_BILL_TEMPLATE', 'rentflow_bill_issued'),
        'language' => env('WHATSAPP_BILL_TEMPLATE_LANGUAGE', 'en_US'),
    ],

    // The approved template for tenant invitations ("Set up my login" button + app links).
    // Uses the connection's template language.
    'invitation_template' => [
        'name' => env('WHATSAPP_INVITATION_TEMPLATE', 'rentflow_tenant_invitation'),
    ],

    // The approved template for payment receipts (receipt PDF as the header document).
    'receipt_template' => [
        'name' => env('WHATSAPP_RECEIPT_TEMPLATE', 'rentflow_payment_receipt'),
    ],

];
