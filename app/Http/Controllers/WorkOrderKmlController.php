<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\Kml\KmlImportService;
use App\Support\WorkOrderUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WorkOrderKmlController extends Controller
{
    public function show(WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);
        abort_unless(KmlImportService::available(), 503, 'Peta order belum tersedia.');
        $latest = $workOrder->kmls()->latest('revision')->first();

        return view('work-orders.kml', ['order' => $workOrder, 'kml' => $latest,
            'uploadLimitBytes' => min(5 * 1024 * 1024, WorkOrderUpload::limitBytes()),
            'history' => $workOrder->kmls()->with('actor')->select(['id_work_order_kmls', 'id_work_orders', 'revision', 'original_name', 'feature_count', 'vertex_count', 'id_users', 'created_at'])->latest('revision')->paginate(10)]);
    }

    public function store(Request $request, WorkOrder $workOrder, KmlImportService $service)
    {
        Gate::authorize('uploadKml', $workOrder);
        abort_unless(KmlImportService::available(), 503, 'Peta order belum tersedia.');
        $file = $request->file('file');
        if ($file instanceof UploadedFile && ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => str_replace(['XLSX hasil export BIMA', 'XLSX'], ['KML', 'KML'], WorkOrderUpload::errorMessage($file->getError()))]);
        }
        // KML MIME varies across browsers. Extension, size, and strict XML geometry validation are authoritative.
        $data = $request->validate(['file' => ['required', 'file', 'extensions:kml', 'max:5120'],
            'order_revision' => 'required|integer|min:0', 'kml_revision' => 'required|integer|min:0'],
            ['file.required' => 'Pilih file KML terlebih dahulu.', 'file.extensions' => 'Gunakan file .kml, bukan KMZ.', 'file.max' => 'Ukuran KML maksimal 5 MB.', 'file.uploaded' => 'Upload KML belum selesai. Pilih ulang file.']);
        try {
            $result = $service->import($request->user(), $workOrder, $request->file('file'), $data);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('work-orders.kml.show', $workOrder)->with('status', $result['changed'] ? 'KML berhasil disimpan. Preview peta tersedia.' : 'File KML identik. Riwayat tidak digandakan.');
    }

    public function data(WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);
        abort_unless(KmlImportService::available(), 503, 'Peta order belum tersedia.');
        $kml = $workOrder->kmls()->latest('revision')->firstOrFail();

        return response()->json($kml->geojson)->header('Cache-Control', 'private, no-store');
    }

    public function download(WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);
        abort_unless(KmlImportService::available(), 503, 'Peta order belum tersedia.');
        $kml = $workOrder->kmls()->latest('revision')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($kml->file_path), 404, 'File KML tidak ditemukan.');

        return Storage::disk('local')->download($kml->file_path, $kml->original_name, ['Content-Type' => 'application/vnd.google-earth.kml+xml', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
