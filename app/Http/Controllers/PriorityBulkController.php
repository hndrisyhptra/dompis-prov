<?php

namespace App\Http\Controllers;

use App\Http\Requests\PriorityBulkRequest;
use App\Services\PriorityBulkBookmarkService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PriorityBulkController extends Controller
{
    public function create(Request $request)
    {
        return view('priorities.bulk', ['area' => $request->user()->role->areaScope(), 'preview' => null, 'numbers' => '']);
    }

    public function preview(PriorityBulkRequest $request, PriorityBulkBookmarkService $bookmarks)
    {
        $numbers = $request->validated('numbers');
        try {
            $preview = $bookmarks->preview($request->user(), $numbers);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('priorities.bulk.create'));
        }

        return response()->view('priorities.bulk', ['area' => $request->user()->role->areaScope(), 'numbers' => $numbers,
            'preview' => $preview])->header('Cache-Control', 'no-store');
    }

    public function store(PriorityBulkRequest $request, PriorityBulkBookmarkService $bookmarks)
    {
        try {
            $result = $bookmarks->commit($request->user(), $request->validated('token'));
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return redirect()->route('priorities.bulk.create')->withErrors($e->errors())
                ->withInput(['numbers' => $request->validated('original_numbers', '')]);
        }

        return redirect()->route('priorities.index')->with('status', $result['added'].' order ditambahkan ke prioritas. '.$result['skipped'].' order sudah dipilih dan dilewati.');
    }
}
