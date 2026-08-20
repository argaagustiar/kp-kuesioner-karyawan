<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Http\Requests\StorePeriodRequest;
use App\Http\Requests\UpdatePeriodRequest;
use App\Http\Resources\PeriodResource;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    public function index(Request $request)
    {
        $query = Period::query();

        if ($request->has('search') && !empty($request->search)) {
            $query->where('description', 'ilike', '%' . $request->search . '%');
        }

        return PeriodResource::collection($query->orderBy('start_date', 'desc')->paginate(10));
    }

    public function store(StorePeriodRequest $request)
    {
        $period = Period::create($request->validated());
        return new PeriodResource($period);
    }

    public function show($id)
    {
        $period = Period::query()->findOrFail($id);
        return new PeriodResource($period);
    }

    public function update(UpdatePeriodRequest $request, $id)
    {
        $period = Period::findOrFail($id);
        $period->update($request->validated());
        return new PeriodResource($period);
    }

    public function updateEvaluationLock(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Only admins can lock or unlock evaluations.');
        }

        $validated = $request->validate([
            'locked' => ['required', 'boolean'],
        ]);

        $period = Period::findOrFail($id);
        $period->update(['evaluation_locked' => $validated['locked']]);

        return new PeriodResource($period->refresh());
    }

    public function destroy($id)
    {
        $period = Period::findOrFail($id);
        
        $period->delete();
        return response()->json(['message' => 'Period deleted successfully']);
    }
}