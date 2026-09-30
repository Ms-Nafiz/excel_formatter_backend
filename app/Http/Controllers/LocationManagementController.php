<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Building;
use Illuminate\Http\Request;

class LocationManagementController extends Controller
{
    // ==========================================
    // AREA MANAGEMENT ENDPOINTS
    // ==========================================

    /**
     * List all registered Area names
     */
    public function indexAreas(Request $request)
    {
        $query = Area::query();

        if ($request->has('search') && !empty($request->search)) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $areas = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'areas' => $areas,
            'total' => $areas->count(),
        ], 200);
    }

    /**
     * Store new Area
     */
    public function storeArea(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string|max:255|unique:areas,name',
        ]);

        $area = Area::create([
            'name' => trim($fields['name']),
        ]);

        return response()->json([
            'message' => 'Area name added successfully!',
            'area' => $area,
        ], 201);
    }

    /**
     * Update Area
     */
    public function updateArea(Request $request, $id)
    {
        $area = Area::findOrFail($id);

        $fields = $request->validate([
            'name' => 'required|string|max:255|unique:areas,name,' . $id,
        ]);

        $area->update([
            'name' => trim($fields['name']),
        ]);

        return response()->json([
            'message' => 'Area name updated successfully!',
            'area' => $area,
        ], 200);
    }

    /**
     * Delete Area
     */
    public function destroyArea($id)
    {
        $area = Area::findOrFail($id);
        $area->delete();

        return response()->json([
            'message' => 'Area name deleted successfully!',
        ], 200);
    }

    // ==========================================
    // BUILDING MANAGEMENT ENDPOINTS
    // ==========================================

    /**
     * List all registered Building names
     */
    public function indexBuildings(Request $request)
    {
        $query = Building::query();

        if ($request->has('search') && !empty($request->search)) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $buildings = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'buildings' => $buildings,
            'total' => $buildings->count(),
        ], 200);
    }

    /**
     * Store new Building
     */
    public function storeBuilding(Request $request)
    {
        $fields = $request->validate([
            'name' => 'required|string|max:255|unique:buildings,name',
        ]);

        $building = Building::create([
            'name' => trim($fields['name']),
        ]);

        return response()->json([
            'message' => 'Building name added successfully!',
            'building' => $building,
        ], 201);
    }

    /**
     * Update Building
     */
    public function updateBuilding(Request $request, $id)
    {
        $building = Building::findOrFail($id);

        $fields = $request->validate([
            'name' => 'required|string|max:255|unique:buildings,name,' . $id,
        ]);

        $building->update([
            'name' => trim($fields['name']),
        ]);

        return response()->json([
            'message' => 'Building name updated successfully!',
            'building' => $building,
        ], 200);
    }

    /**
     * Delete Building
     */
    public function destroyBuilding($id)
    {
        $building = Building::findOrFail($id);
        $building->delete();

        return response()->json([
            'message' => 'Building name deleted successfully!',
        ], 200);
    }
}
