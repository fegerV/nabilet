<?php
namespace Ps;
use Illuminate\Database\Eloquent\Model;

class Plain { public function __get($k) { return 1; } }
function a(Plain $p) { return $p->bar; }

class M extends Model {}
function b(M $m) { return $m->public_id; }

function c(Model $m) { return $m->public_id; }
