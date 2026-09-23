<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $role_id
 * @property int $permission_id
 */
class RolePermission extends Model
{
    protected $table = 'role_permissions';
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = ['role_id', 'permission_id'];

    protected $fillable = [
        'role_id',
        'permission_id',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
