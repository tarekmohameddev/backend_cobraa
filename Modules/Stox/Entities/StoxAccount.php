<?php

declare(strict_types=1);

namespace Modules\Stox\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class StoxAccount extends Model
{
    use HasFactory;

    protected $table = 'stox_accounts';

    protected $fillable = [
        'name',
        'description',
        'status',
        'base_url',
        'bearer_token',
        'webhook_signature',
        'settings',
        'default_payment_mapping',
        'auto_export_statuses',
        'export_delay_minutes',
    ];

    protected $casts = [
        'settings' => 'array',
        'default_payment_mapping' => 'array',
        'auto_export_statuses' => 'array',
        'export_delay_minutes' => 'integer',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(StoxOrder::class, 'stox_account_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getBearerTokenAttribute(?string $value): string
    {
        return $value ? Crypt::decryptString($value) : '';
    }

    public function setBearerTokenAttribute(string $value): void
    {
        $this->attributes['bearer_token'] = Crypt::encryptString($value);
    }

    public function getEncryptedBearerToken(): string
    {
        return $this->attributes['bearer_token'];
    }
}

