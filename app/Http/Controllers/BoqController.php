<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\WorkOrder;
use App\Services\Boq\BoqBreakdown;
use App\Services\Boq\BoqImportService;
use App\Support\WorkOrderUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BoqController extends Controller
{
    public function create(WorkOrder $workOrder, BoqImportService $service)
    {
        Gate::authorize('importBoq', $workOrder);
        abort_unless(BoqImportService::available(), 503, 'RAB/BOQ belum tersedia. Migrasi perhitungan BOQ perlu diselesaikan.');
        abort_unless($service->eligible($workOrder), 422, 'RAB/BOQ tersedia setelah order pernah diperbarui ke JT.');

        return view('boqs.upload', ['order' => $workOrder->load('boq'), 'uploadLimitBytes' => WorkOrderUpload::limitBytes(), 'packages' => Package::orderBy('name')->get(), 'uploadLimitMb' => WorkOrderUpload::limitBytes() / 1024 / 1024]);
    }

    public function store(Request $request, WorkOrder $workOrder, BoqImportService $service)
    {
        Gate::authorize('importBoq', $workOrder);
        abort_unless(BoqImportService::available(), 503, 'RAB/BOQ belum tersedia. Migrasi perhitungan BOQ perlu diselesaikan.');
        $file = $request->file('file');
        if ($file instanceof UploadedFile && ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => str_replace(['XLSX hasil export BIMA', 'XLSX'], ['RAB/BOQ', 'XLSX/CSV'], WorkOrderUpload::errorMessage($file->getError()))]);
        }
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv', 'max:10240'],
            'package_id' => 'required|integer', 'order_revision' => 'required|integer|min:0', 'boq_revision' => 'required|integer|min:0',
            'sheet' => 'nullable|string|max:100', 'header_row' => 'required|integer|min:1|max:50'], ['file.required' => 'Pilih file XLSX/CSV RAB/BOQ terlebih dahulu.', 'file.mimes' => 'Gunakan file XLSX atau CSV.', 'file.extensions' => 'Gunakan file XLSX atau CSV.', 'file.max' => 'Ukuran file melebihi batas aplikasi 10 MB.', 'file.uploaded' => 'Upload belum selesai. Pilih ulang file RAB/BOQ.']);
        try {
            $result = $service->import($request->user(), $workOrder, $request->file('file'), $data);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('boqs.show', $workOrder)->with('status', $result['changed'] ? 'RAB/BOQ berhasil diimpor dan dicatat pada riwayat.' : 'Isi BOQ identik. Data dan riwayat tidak digandakan.');
    }

    public function show(WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);
        abort_unless(BoqImportService::available(), 503, 'RAB/BOQ belum tersedia. Migrasi perhitungan BOQ perlu diselesaikan.');
        $boq = $workOrder->boq()->with(['package', 'items', 'histories.actor'])->firstOrFail();

        return view('boqs.show', ['order' => $workOrder, 'boq' => $boq, 'breakdown' => BoqBreakdown::totals($boq->items)]);
    }

    public function download(WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);
        abort_unless(BoqImportService::available(), 503, 'RAB/BOQ belum tersedia. Migrasi perhitungan BOQ perlu diselesaikan.');
        $boq = $workOrder->boq()->firstOrFail();
        abort_unless(Storage::disk('local')->exists($boq->file_path), 404, 'File sumber tidak ditemukan.');

        return Storage::disk('local')->download($boq->file_path, $boq->original_name, ['Cache-Control' => 'private, no-store']);
    }

    public function template(Request $request, WorkOrder $workOrder)
    {
        Gate::authorize('importBoq', $workOrder);
        abort_unless(BoqImportService::available(), 503, 'RAB/BOQ belum tersedia. Migrasi perhitungan BOQ perlu diselesaikan.');

        if ($request->has('package')) {
            $package = (string) $request->query('package');
            abort_unless(in_array($package, ['5', '10'], true), 404);

            return response()->download(resource_path('templates/boq/paket-'.$package.'.xlsx'), 'FORMAT BOQ DOMPIS - PAKET '.$package.'.xlsx', ['Cache-Control' => 'private, no-store']);
        }

        return response()->streamDownload(function () {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['DESIGNATOR', 'QTY', 'TYPE'], escape: '');
            fclose($stream);
        }, 'template-rab-boq.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
