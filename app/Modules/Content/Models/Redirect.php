<?php

declare(strict_types=1);

namespace NabileT\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $source_path
 * @property string $target_path
 * @property int $status_code 301|302|307|308
 * @property \Carbon\CarbonImmutable $created_at
 */
class Redirect extends Model
{
    protected $table = 'redirects';

    protected $fillable = [
        'source_path',
        'target_path',
        'status_code',
    ];

    protected $casts = [
        'status_code' => 'integer',
    ];
}
