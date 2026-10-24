<?php
namespace Nabilet\Modules\Webhooks\Models;
use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
class Webhook extends Model
{
    use HasPublicId;

    protected $table = 'webhooks';
    protected $fillable = ['organization_id', 'url', 'secret_encrypted', 'events_json', 'active', 'retry_limit'];
}