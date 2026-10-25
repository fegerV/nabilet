<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

/**
 * EventCategory Model
 */
class EventCategory extends Model
{
    use HasPublicId;

    protected $table = 'event_categories';

    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'status',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'category_id');
    }
}
