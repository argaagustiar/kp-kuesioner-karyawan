<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    // GET /api/employees
    public function index(Request $request)
    {
        $query = Employee::with(['position', 'department', 'subordinates']);

        // Fitur Pencarian
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhereHas('position', function($posQuery) use ($search) {
                      $posQuery->where('title', 'ilike', "%{$search}%");
                  });
            });   
        }

        if ($request->has('role')) {
            if ($request->role == 'employee') {
                $query->whereHas('subordinates', function($mgrQuery) use ($request) {
                    $mgrQuery->where('manager_id', $request->user()->employee_id);
                })
                ->orWhereHas('heads', function($mgrQuery) use ($request) {
                    $mgrQuery->where('manager_id', $request->user()->employee_id);
                })
                ->orWhereHas('coworkers', function($mgrQuery) use ($request) {
                    $mgrQuery->where('manager_id', $request->user()->employee_id);
                })
                ->orWhere('id', $request->user()->employee_id);
            }
        }

        // Filter Active Only (Optional)
        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->has('period_id')) {
            $query->with(['evaluations' => function($evalQuery) use ($request) {
                $evalQuery->where('period_id', $request->period_id)
                          ->where('evaluator_id', $request->user()->employee_id);
            }]);
        }

        $sortBy = $request->input('sort_by', 'name'); 
        $sortDirection = $request->input('sort_direction', 'asc');

        $allowedSorts = ['name', 'email', 'employee_code', 'join_date', 'end_contract_date', 'created_at'];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $employees = $query->orderBy($sortBy, $sortDirection)->paginate($request->input('per_page', 10));

        return EmployeeResource::collection($employees);
    }

    // POST /api/employees
    public function store(StoreEmployeeRequest $request)
    {
        return DB::transaction(function () use ($request) {
            // 1. Create Employee Data
            $employee = Employee::create($request->validated());

            // 2. Sync Departments (Many-to-Many Pivot)
            // Format input front-end: departments: [{id: 1, is_primary: true}, {id: 2, is_primary: false}]
            if ($request->has('department')) {
                $deptSyncData = [];
                foreach ($request->departments as $dept) {
                    $deptSyncData[$dept['id']] = ['is_primary' => $dept['is_primary'] ?? false];
                }
                $employee->departments()->sync($deptSyncData);
            }

            // 3. Sync Managers (Many-to-Many Pivot)
            if ($request->has('managers')) {
                $managerSyncData = [];
                foreach ($request->managers as $mgr) {
                    $managerSyncData[$mgr['id']] = ['reporting_type' => $mgr['reporting_type'] ?? 'direct'];
                }
                $employee->managers()->sync($managerSyncData);
            }

            // Load relations agar response lengkap
            $employee->load(['position', 'department', 'managers']);

            return new EmployeeResource($employee);
        });
    }

    // GET /api/employees/{id}
    public function show($id)
    {
        $employee = Employee::with(['position', 'department'])->findOrFail($id);
        return new EmployeeResource($employee);
    }

    // PUT /api/employees/{id}
    public function update(UpdateEmployeeRequest $request, $id)
    {
        $employee = Employee::findOrFail($id);

        return DB::transaction(function () use ($request, $employee) {
            // 1. Update Basic Data
            $employee->update($request->validated());

            // 2. Sync Departments (Hapus yang lama, pasang yang baru sesuai input)
            if ($request->has('department')) {
                $deptSyncData = [];
                foreach ($request->departments as $dept) {
                    $deptSyncData[$dept['id']] = ['is_primary' => $dept['is_primary'] ?? false];
                }
                $employee->departments()->sync($deptSyncData);
            }

            // 3. Sync Managers
            if ($request->has('managers')) {
                $managerSyncData = [];
                foreach ($request->managers as $mgr) {
                    $managerSyncData[$mgr['id']] = ['reporting_type' => $mgr['reporting_type'] ?? 'direct'];
                }
                $employee->managers()->sync($managerSyncData);
            }

            // Refresh data terbaru
            $employee->refresh()->load(['position', 'department', 'managers']);

            return new EmployeeResource($employee);
        });
    }

    // DELETE /api/employees/{id}
    public function destroy($id)
    {
        $employee = Employee::findOrFail($id);
        
        // Soft Delete (karena di model sudah pakai SoftDeletes)
        // Relasi pivot otomatis aman karena kita pakai ->wherePivotNull di Model
        $employee->delete();

        return response()->json(['message' => 'Employee deleted successfully']);
    }
}