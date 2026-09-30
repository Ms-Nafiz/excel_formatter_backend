<?php

namespace App\Http\Controllers;

use App\Models\Collector;
use Illuminate\Http\Request;

class CollectorManagementController extends Controller
{
    /**
     * List all Collectors with assigned areas
     */
    public function index(Request $request)
    {
        $query = Collector::with('areas');

        if ($request->has('search') && !empty($request->search)) {
            $query->where('name', 'like', '%' . $request->search . '%')
                ->orWhere('phone', 'like', '%' . $request->search . '%');
        }

        $collectors = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'collectors' => $collectors,
            'total' => $collectors->count(),
        ], 200);
    }

    /**
     * Store new Collector and assign multiple areas
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'area_ids' => 'nullable|array',
            'area_ids.*' => 'exists:areas,id',
        ]);

        $collector = Collector::create([
            'name' => trim($fields['name']),
            'phone' => isset($fields['phone']) ? trim($fields['phone']) : null,
            'status' => 'active',
        ]);

        if (isset($fields['area_ids'])) {
            $collector->areas()->sync($fields['area_ids']);
        }

        $collector->load('areas');

        return response()->json([
            'message' => 'Collector created & areas assigned successfully!',
            'collector' => $collector,
        ], 201);
    }

    /**
     * Update Collector & sync multiple areas
     */
    public function update(Request $request, $id)
    {
        $collector = Collector::findOrFail($id);

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'area_ids' => 'nullable|array',
            'area_ids.*' => 'exists:areas,id',
        ]);

        $collector->update([
            'name' => trim($fields['name']),
            'phone' => isset($fields['phone']) ? trim($fields['phone']) : null,
        ]);

        if (isset($fields['area_ids'])) {
            $collector->areas()->sync($fields['area_ids']);
        }

        $collector->load('areas');

        return response()->json([
            'message' => 'Collector details updated successfully!',
            'collector' => $collector,
        ], 200);
    }

    /**
     * Delete Collector
     */
    public function destroy($id)
    {
        $collector = Collector::findOrFail($id);
        $collector->areas()->detach();
        $collector->delete();

        return response()->json([
            'message' => 'Collector deleted successfully!',
        ], 200);
    }
}
