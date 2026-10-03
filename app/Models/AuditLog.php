<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model { protected $table = 'audit_logs'; protected $fillable = ['user_id', 'organization_id', 'action', 'entity_type', 'entity_id', 'old_values_json', 'new_values_json', 'ip_address', 'user_agent']; }