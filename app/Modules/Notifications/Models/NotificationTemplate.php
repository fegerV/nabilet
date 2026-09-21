<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $channel 'email' | 'sms' | 'push'
 * @property string $subject_template
 * @property string $body_template
 * @property array $variables
 * @property bool $is_active
 */
class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    protected $fillable = [
        'name',
        'channel',
        'subject_template',
        'body_template',
        'variables',
        'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
