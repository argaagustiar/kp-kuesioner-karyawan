<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Department::with('parent'); // Eager load parent

        if ($request->has('search') && !empty($request->search)) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        // Jika ingin melihat struktur pohon (hanya root department)
        if ($request->has('tree')) {
            $query->whereNull('parent_id')->with('children');
        }

        return DepartmentResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreDepartmentRequest $request)
    {
        $department = Department::create($request->validated());
        return new DepartmentResource($department);
    }

    public function show($id)
    {
        $department = Department::with(['parent', 'children'])->findOrFail($id);
        return new DepartmentResource($department);
    }

    public function update(UpdateDepartmentRequest $request, $id)
    {
        $department = Department::findOrFail($id);
        $department->update($request->validated());
        return new DepartmentResource($department);
    }

    public function destroy($id)
    {
        $department = Department::findOrFail($id);

        // Cek apakah punya sub-department? (Opsional, tergantung kebijakan)
        if ($department->children()->count() > 0) {
            return response()->json(['message' => 'Cannot delete department with sub-departments.'], 400);
        }

        $department->delete();
        return response()->json(['message' => 'Department deleted successfully']);
    }
}