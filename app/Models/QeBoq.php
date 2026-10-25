<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QeBoq extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_qe_boqs';

    protected $guarded = ['id_qe_boqs'];

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
        return $this->hasMany(QeBoqItem::class, 'id_qe_boqs');
    }

    public function histories()
    {
        return $this->hasMany(QeBoqHistory::class, 'id_qe_boqs')->latest('id_qe_boq_histories');
    }

    protected function casts(): array
    {
        return ['grand_total' => 'decimal:2', 'material_total' => 'decimal:2', 'labor_total' => 'decimal:2'];
    }
}
