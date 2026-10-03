<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property string $name
 * @property int $session_id
 * @property int $created_by
 * @property string $encrypted_payload
 * @property \Carbon\CarbonImmutable $created_at
 */
class OfflineBundle extends Model
{
    protected $table = 'offline_bundles';

    protected $fillable = [
        'name',
        'session_id',
        'created_by',
        'encrypted_payload',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
