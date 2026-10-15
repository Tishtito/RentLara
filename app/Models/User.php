<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Admin-portal login: super admin, landlord or caretaker.
 *
 * Deliberately NOT using BelongsToAccount: the auth guard must load the user
 * before any account context exists, and super admins have no account at all.
 * The SetCurrentAccount middleware reads $user->account_id to set the context.
 *
 * Replaces Laravel's default app/Models/User.php.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'account_id', 'role', 'name', 'email', 'phone', 'password', 'status',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Properties this user looks after as caretaker. */
    public function caretakerProperties(): HasMany
    {
        return $this->hasMany(Property::class, 'caretaker_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isLandlord(): bool
    {
        return $this->role === UserRole::Landlord;
    }

    public function isCaretaker(): bool
    {
        return $this->role === UserRole::Caretaker;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
