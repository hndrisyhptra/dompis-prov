<?php

namespace App\Models;

use App\Enums\ProgressCategory;
use App\Enums\Role;
use App\Services\WorkOrderDerivedFields;
use App\Services\WorkOrderMappingResolver;
use App\Support\PsEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    protected $primaryKey = 'id_work_orders';

    public const STATUSES = ['NEW', 'ASSIGNED', 'SURVEY', 'SCHEDULED', 'IN_PROGRESS', 'BLOCKED', 'FIELD_DONE', 'PENDING_VALIDATION', 'COMPLETED', 'CANCELLED'];

    public const POTENTIAL_PS_STATUSES = [
        'Activate Access Manual-STARTWORK',
        'Activate Cross Connect-STARTWORK',
        'Activate Global-COMPLETE',
        'Activate Metro Manual-COMPLETE',
        'Activate Metro Manual-STARTWORK',
        'Activate NCIX-STARTWORK',
        'Activate Service-STARTWORK',
        'Activation Power-STARTWORK',
    ];

    public const NEW_ORDER_STATUSES = [
        'Review Order TSQ Metro Manual-COMPLETE',
        'Review Order TSQ Metro Manual-STARTWORK',
        'Review Order TSQ Modify Metro Manual-COMPLETE',
        'Review Order TSQ Modify Metro Manual-STARTWORK',
        'Review Order TSQ WDM-COMPLETE',
        'Review Order TSQ WDM-STARTWORK',
        'Review Order TSQ-STARTWORK',
    ];

    protected $guarded = ['id_work_orders'];

    protected function casts(): array
    {
        return ['device_overrides' => 'array', 'source_bima_fields' => 'array', 'tgl_order' => 'date', 'tgl_ps' => 'date',
            'branch_completed_at' => 'datetime', 'last_pulled_at' => 'datetime', 'last_progress_at' => 'datetime', 'verified_at' => 'datetime', 'field_done_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $order) {
            $order->forceFill(WorkOrderDerivedFields::dates($order->tgl_order?->toDateString()));
            $order->status_order = WorkOrderDerivedFields::status($order->task_bima, $order->status_bima);
            // Branch progress/device saves must not restore a stale classification during a reference refresh.
            if (! $order->exists || $order->isDirty(['task_bima', 'status_bima', 'status_order'])) {
                $mapping = app(WorkOrderMappingResolver::class)->resolve($order->task_bima, $order->status_bima);
                $order->mapping_order = $mapping?->mapping_order;
                $order->id_mapping_order = $mapping?->getKey();
            }
        });
    }

    public function getUmurHariAttribute(): ?int
    {
        return WorkOrderDerivedFields::dates($this->tgl_order?->toDateString())['umur_hari'];
    }

    public function getKategoriUmurAttribute(): ?string
    {
        return WorkOrderDerivedFields::dates($this->tgl_order?->toDateString())['kategori_umur'];
    }

    public function taskLogs()
    {
        return $this->hasMany(BimaTaskLog::class, 'id_work_orders')->latest('id_bima_task_logs');
    }

    public function priorities()
    {
        return $this->hasMany(WorkOrderPriority::class, 'id_work_orders');
    }

    public function orderMapping()
    {
        return $this->belongsTo(MappingOrder::class, 'id_mapping_order');
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
        return PsEligibility::closedOrders($query, true);
    }

    public function scopePotentialPs(Builder $query): Builder
    {
        return $query->whereIn('status_order', self::POTENTIAL_PS_STATUSES);
    }

    public function scopeNewOrder(Builder $query): Builder
    {
        return $query->whereIn('status_order', self::NEW_ORDER_STATUSES);
    }

    public function scopeWaitingBima(Builder $query): Builder
    {
        return PsEligibility::closedOrders($query, false);
    }

    public function scopePsOnDate(Builder $query, string $date): Builder
    {
        return $query->psEligible()->whereDate('tgl_ps', $date);
    }

    public function isPsEligible(): bool
    {
        return PsEligibility::matches($this->progress_category, $this->status_order, $this->task_bima, $this->status_bima);
    }

    public function kmls()
    {
        return $this->hasMany(WorkOrderKml::class, 'id_work_orders');
    }

    public function boq()
    {
        return $this->hasOne(QeBoq::class, 'id_work_orders');
    }

    public function assignedBranch()
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
