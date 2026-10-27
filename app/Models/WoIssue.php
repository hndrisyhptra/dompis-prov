<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WoIssue extends Model
{
    protected $primaryKey = 'id_wo_issues';

    protected $guarded = ['id_wo_issues'];

    public function notes()
    {
        return $this->hasMany(WoIssueNote::class, 'id_wo_issues')->orderBy('id_wo_issue_notes');
    }

    public function latestNote()
    {
        return $this->hasOne(WoIssueNote::class, 'id_wo_issues')->ofMany(['created_at' => 'max', 'id_wo_issue_notes' => 'max']);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'id_work_orders');
    }

    protected function casts(): array
    {
        return ['eta' => 'date', 'resolved_at' => 'datetime'];
    }
}
