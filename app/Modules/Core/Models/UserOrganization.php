<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * UserOrganization Pivot Model
 */
class UserOrganization extends Pivot
{
    protected $table = 'user_organization';

    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'organization_id',
        'role_id',
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
}
