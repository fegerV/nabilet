<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CartItem extends Model { protected $table = 'cart_items'; protected $fillable = ['cart_id', 'inventory_item_id', 'quantity', 'unit_price', 'total_price', 'seat_snapshot_json']; }