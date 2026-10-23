<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\WorkOrderDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkOrderDeviceController extends Controller
{
    public function update(Request $request, WorkOrder $workOrder, WorkOrderDeviceService $service)
    {
        Gate::authorize('editDevices', $workOrder);
        $rules = ['revision' => 'required|integer|min:0'];
        foreach (WorkOrderDeviceService::FIELDS as $field => $label) {
            $rules[$field] = 'present|nullable|string|max:255';
        }
        $service->update($request->user(), $workOrder, $request->validate($rules));

        return back()->with('status', 'Data perangkat disimpan. Nilai ini dipertahankan pada upload berikutnya.');
    }
}
