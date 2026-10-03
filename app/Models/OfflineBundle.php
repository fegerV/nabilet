<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OfflineBundle extends Model { protected $table = 'offline_bundles'; protected $fillable = ['organization_id', 'checkin_device_id', 'event_id', 'session_id', 'bundle_hash', 'schema_version', 'public_key_fingerprint', 'ticket_count', 'revoked_count', 'payload_json', 'status', 'generated_at', 'downloaded_at', 'expires_at'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }