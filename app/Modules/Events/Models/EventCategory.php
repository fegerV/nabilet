<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EventCategory Model
 */
class EventCategory extends Model
{
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
