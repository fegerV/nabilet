<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission Model
 */
class Permission extends Model
{
    protected $table = 'permissions';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
