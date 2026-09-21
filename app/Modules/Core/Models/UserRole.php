<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * UserRole Model
 */
class UserRole extends Pivot
{
    protected $table = 'user_roles';

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'organization_id',
        'role_id',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
