<?php

namespace App\Models;

use App\Enums\ProgressCategory;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    protected $primaryKey = 'id_work_orders';

    public const STATUSES = ['NEW', 'ASSIGNED', 'SURVEY', 'SCHEDULED', 'IN_PROGRESS', 'BLOCKED', 'FIELD_DONE', 'PENDING_VALIDATION', 'COMPLETED', 'CANCELLED'];

    protected $guarded = ['id_work_orders'];

    protected function casts(): array
    {
        return ['source_bima_fields' => 'array', 'order_date' => 'date', 'bima_ps_date' => 'date',
            'branch_completed_at' => 'datetime', 'last_pulled_at' => 'datetime', 'last_progress_at' => 'datetime', 'verified_at' => 'datetime', 'field_done_at' => 'datetime'];
    }

    public function progressLabel(): string
    {
        return $this->progress_category ?? match ($this->operational_status) {
            'NEW' => 'Belum diperbarui', 'ASSIGNED' => 'Ditugaskan', 'IN_PROGRESS' => 'Dalam pengerjaan',
            'BLOCKED' => 'Kendala', 'FIELD_DONE' => 'Selesai lapangan', 'PENDING_VALIDATION' => 'Menunggu validasi',
            'COMPLETED' => 'Selesai tervalidasi', 'CANCELLED' => 'Dibatalkan', default => $this->operational_status,
        };
    }

    public function terminal(): bool
    {
        return ProgressCategory::tryFrom(strtoupper(trim($this->progress_category ?? '', ' ')))?->terminal() || in_array($this->operational_status, ['COMPLETED', 'CANCELLED'], true);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('branch_completed_at')->whereNotIn('operational_status', ['COMPLETED', 'CANCELLED'])
            ->where(fn ($q) => $q->whereNull('progress_category')->orWhereRaw('UPPER(TRIM(progress_category)) NOT IN (?, ?, ?)', ['CLOSE', 'CANCEL', 'CLOSE ON DESK']));
    }

    public function scopePsEligible(Builder $query): Builder
    {
        return $query->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CLOSE'])
            ->whereRaw('UPPER(TRIM(bima_status)) IN (?, ?)', ['CLOSE', 'COMPLETE']);
    }

    public function scopePsOnDate(Builder $query, string $date): Builder
    {
        return $query->psEligible()->whereDate('bima_ps_date', $date);
    }

    public function isPsEligible(): bool
    {
        return strtoupper(trim($this->progress_category ?? '', ' ')) === 'CLOSE'
            && in_array(strtoupper(trim($this->bima_status ?? '', ' ')), ['CLOSE', 'COMPLETE'], true);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'id_branches')->withTrashed();
    }

    public function events()
    {
        return $this->hasMany(WoStatusEvent::class, 'id_work_orders')->latest('id_wo_status_events');
    }

    public function issues()
    {
        return $this->hasMany(WoIssue::class, 'id_work_orders')->latest('id_wo_issues');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->active || $user->role === Role::Bot) {
            return $query->whereRaw('1 = 0');
        }

        return $user->role->areaScope() ? $query : $query->whereIn('id_branches', $user->branches()->where('active', true)->select('branches.id_branches'));
    }
}
