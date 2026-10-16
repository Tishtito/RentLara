<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A landlord's WhatsApp Business number on the Meta WhatsApp Cloud API.
 * Managed through App\Services\WhatsApp\WhatsAppConnectionService.
 */
class WhatsAppConnection extends Model
{
    use BelongsToAccount, HasFactory;

    protected $table = 'whatsapp_connections';

    protected $fillable = [
        'account_id', 'phone_number_id', 'business_account_id', 'access_token', 'app_secret',
        'webhook_verify_token', 'display_phone_number', 'verified_name', 'template_name',
        'template_language', 'status', 'verified_at',
    ];

    protected $hidden = ['access_token', 'app_secret'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
