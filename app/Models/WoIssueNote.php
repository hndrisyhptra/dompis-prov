<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WoIssueNote extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'id_wo_issue_notes';

    protected $guarded = ['id_wo_issue_notes'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'id_users');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
