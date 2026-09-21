<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Seat extends Model {
    protected $table = 'seats';
    protected $fillable = ['row_id', 'number', 'label', 'x', 'y', 'width', 'height', 'rotation', 'type', 'status', 'metadata_json'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    }
}