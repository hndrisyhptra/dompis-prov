<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoqJt extends Model
{
    use SoftDeletes;

    protected $table = 'boq_jt';

    protected $primaryKey = 'id_boq_jt';

    protected $guarded = ['id_boq_jt'];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'id_work_orders');
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id')->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(BoqItem::class, 'id_boq_jt');
    }

    public function histories()
    {
        return $this->hasMany(BoqHistory::class, 'id_boq_jt')->latest('id_boq_histories');
    }

    protected function casts(): array
    {
        return ['grand_total' => 'decimal:2', 'material_total' => 'decimal:2', 'labor_total' => 'decimal:2'];
    }
}
