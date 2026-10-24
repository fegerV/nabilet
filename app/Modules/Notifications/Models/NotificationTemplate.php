<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $channel
 * @property string $locale
 * @property string|null $subject
 * @property string|null $body_text
 * @property string|null $body_html
 * @property bool $active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class NotificationTemplate extends Model
{
    use HasPublicId;

    /** Явно, а не через множественное число Eloquent — см. `Consent`. */
    protected $table = 'notification_templates';

    protected $fillable = [
        'public_id',
        'code',
        'channel',
        'locale',
        'subject',
        'body_text',
        'body_html',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
