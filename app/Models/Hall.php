<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Hall extends Model { protected $table = 'halls'; protected $fillable = ['venue_id', 'name', 'description', 'capacity', 'width', 'height', 'status'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }