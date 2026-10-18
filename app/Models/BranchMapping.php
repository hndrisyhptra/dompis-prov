<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchMapping extends Model
{
    protected $primaryKey = 'id_branch_mappings';

    protected $guarded = ['id_branch_mappings'];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'id_branches');
    }
}
