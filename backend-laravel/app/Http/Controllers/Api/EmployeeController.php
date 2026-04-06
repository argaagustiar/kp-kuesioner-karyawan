<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Evaluation;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    // GET /api/employees
    public function index(Request $request)
    {
        $query = Employee::with(['position', 'department', 'subordinates']);
        $employee = $request->user()->load('employee');

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
                ->orWhere('department_id', $employee->employee->department_id)
                ->orWhere('id', $request->user()->employee_id);
            }
        }

        // Filter Active Only (Optional)
        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->has('period_id')) {
            $query->withExists(['evaluations as has_current_evaluation' => function($evalQuery) use ($request) {
                $evalQuery->where('period_id', $request->period_id)
                          ->where('evaluator_id', $request->user()->employee_id);
            }])->with(['evaluations' => function($evalQuery) use ($request) {
                $evalQuery->where('period_id', $request->period_id)
                          ->where('evaluator_id', $request->user()->employee_id);
            }]);
        }

        $sortBy = $request->input('sort_by', 'name'); 
        $sortDirection = $request->input('sort_direction', 'asc');

        $allowedSorts = ['name', 'email', 'employee_code', 'join_date', 'end_contract_date', 'created_at', 'has_current_evaluation'];

        if ($request->has('role')) {
            if ($request->role == 'employee') {
                $query->orderBy('has_current_evaluation', 'asc');
            }
        }

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $employees = $query->paginate($request->input('per_page', 10));

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

    // GET /api/employees/hr-evaluation-status
    public function hrEvaluationStatus(Request $request)
    {
        $request->validate([
            'period_id' => 'required|exists:periods,id',
        ]);

        $periodId = $request->period_id;

        $employees = Employee::with(['position', 'department', 'heads', 'subordinates', 'coworkers'])
            ->where('is_active', true)
            ->get();

        $result = $employees->map(function ($employee) use ($periodId) {
            // Ambil semua evaluator untuk employee ini
            $heads = $employee->heads->map(function ($head) {
                return [
                    'id' => $head->id,
                    'name' => $head->name,
                    'role' => 'Manager',
                ];
            });

            $subordinates = $employee->subordinates->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'name' => $sub->name,
                    'role' => 'Subordinate',
                ];
            });

            $coworkers = $employee->coworkers->map(function ($coworker) {
                return [
                    'id' => $coworker->id,
                    'name' => $coworker->name,
                    'role' => 'Coworker',
                ];
            });

            // Tambah dept coworkers
            $deptEmployees = Employee::where('department_id', $employee->department_id)
                ->where('id', '!=', $employee->id)
                ->get();

            $deptcoworkers = $deptEmployees->map(function ($deptEmp) {
                return [
                    'id' => $deptEmp->id,
                    'name' => $deptEmp->name,
                    'role' => 'Dept Coworker',
                ];
            });

            $self = [
                'id' => $employee->id,
                'name' => $employee->name,
                'role' => 'Self',
            ];

            $evaluators = array_merge($heads->toArray(), $subordinates->toArray(), $coworkers->toArray(), $deptcoworkers->toArray(), [$self]);
            $evaluators = collect($evaluators)->unique('id')->values()->toArray();

            // Collect evaluator IDs
            $evaluatorIds = array_column($evaluators, 'id');

            // Cek evaluation untuk setiap evaluator
            $evaluationQuery = Evaluation::where('period_id', $periodId)
                ->where('employee_id', $employee->id)
                ->whereIn('evaluator_id', $evaluatorIds);

            foreach ($evaluators as &$evaluator) {
                $hasEvaluation = (clone $evaluationQuery)->where('evaluator_id', $evaluator['id'])->exists();
                $evaluator['evaluation_status'] = $hasEvaluation ? 'evaluated' : 'pending';
            }

            // Hitung status overall
            $evaluatedCount = count(array_filter($evaluators, fn($e) => $e['evaluation_status'] === 'evaluated'));
            $totalEvaluators = count($evaluators);
            $overallStatus = $evaluatedCount === $totalEvaluators ? 'fully_evaluated' : 
                           ($evaluatedCount > 0 ? 'partially_evaluated' : 'not_evaluated');

            return [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->name,
                'position' => $employee->position ? [
                    'id' => $employee->position->id,
                    'title' => $employee->position->title,
                ] : null,
                'department' => $employee->department ? [
                    'id' => $employee->department->id,
                    'name' => $employee->department->name,
                ] : null,
                'evaluators' => $evaluators,
                'evaluation_summary' => [
                    'total_evaluators' => $totalEvaluators,
                    'evaluated_count' => $evaluatedCount,
                    'pending_count' => $totalEvaluators - $evaluatedCount,
                    'overall_status' => $overallStatus,
                ],
            ];
        });

        return response()->json([
            'data' => $result->sortBy('name')->values(),
            'period' => Period::find($periodId) ? Period::find($periodId)->only(['id', 'name']) : null,
        ]);
    }

    // GET /api/employees/{id}/hr-evaluation-status
    public function hrEvaluationStatusByEmployee(Request $request, $id)
    {
        $request->validate([
            'period_id' => 'required|exists:periods,id',
        ]);

        $periodId = $request->period_id;

        $employee = Employee::with(['position', 'department', 'heads', 'subordinates', 'coworkers'])
            ->where('is_active', true)
            ->findOrFail($id);

        $deptEmployees = Employee::where('department_id', $employee->department_id)->get();

        $deptcoworkers = $deptEmployees->map(function ($deptEmp) {
            return [
                'id' => $deptEmp->id,
                'name' => $deptEmp->name,
                'role' => 'Coworker',
            ];
        });

        $heads = $employee->heads->map(function ($head) {
            return [
                'id' => $head->id,
                'name' => $head->name,
                'role' => 'Manager',
            ];
        });

        $subordinates = $employee->subordinates->map(function ($sub) {
            return [
                'id' => $sub->id,
                'name' => $sub->name,
                'role' => 'Subordinate',
            ];
        });

        $coworkers = $employee->coworkers->map(function ($coworker) {
            return [
                'id' => $coworker->id,
                'name' => $coworker->name,
                'role' => 'Coworker',
            ];
        });

        $self = [
            'id' => $employee->id,
            'name' => $employee->name,
            'role' => 'Self',
        ];

        $evaluators = array_merge($deptcoworkers->toArray(), $heads->toArray(), $subordinates->toArray(), $coworkers->toArray(), [$self]);
        $evaluators = collect($evaluators)->unique('id')->values()->toArray();

        $evaluatorIds = $employee->heads->pluck('id')->toArray();
        $evaluatorIds = array_merge($evaluatorIds, $employee->subordinates->pluck('id')->toArray());
        $evaluatorIds = array_merge($evaluatorIds, $employee->coworkers->pluck('id')->toArray());
        $evaluatorIds = array_merge($evaluatorIds, $deptEmployees->pluck('id')->toArray());
        $evaluatorIds[] = $id;

        $evaluation = Evaluation::where('period_id', $periodId)
            ->where('employee_id', $employee->id)
            ->whereIn('evaluator_id', $evaluatorIds);


        foreach ($evaluators as &$evaluator) {
            $hasEvaluation = $evaluation->where('evaluator_id', $evaluator['id'])->exists();
            $evaluator['evaluation_status'] = $hasEvaluation ? 'evaluated' : 'pending';
        }

        $result = [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->name,
            'position' => $employee->position ? [
                'id' => $employee->position->id,
                'title' => $employee->position->title,
            ] : null,
            'department' => $employee->department ? [
                'id' => $employee->department->id,
                'name' => $employee->department->name,
            ] : null,
            'evaluators' => $evaluators,
            'evaluation_status' => $hasEvaluation ? 'evaluated' : 'pending',
        ];

        return response()->json([
            'data' => $result,
            'period' => Period::find($periodId) ? Period::find($periodId)->only(['id', 'name']) : null,
        ]);
    }
}