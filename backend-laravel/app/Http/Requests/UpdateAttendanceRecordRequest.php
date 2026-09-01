<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceRecordRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'period_id' => 'required|uuid|exists:periods,id',
            'employee_id' => 'required|uuid|exists:employees,id',
            'sick' => 'nullable|numeric|min:0',
            'work_accident' => 'nullable|numeric|min:0',
            'permit' => 'nullable|numeric|min:0',
            'awol' => 'nullable|numeric|min:0',
            'late_permit' => 'nullable|numeric|min:0',
            'early_leave' => 'nullable|numeric|min:0',
            'annual_leave' => 'nullable|numeric|min:0',
            'late' => 'nullable|numeric|min:0',
            'warning_letter_1' => 'nullable|numeric|min:0',
            'warning_letter_2' => 'nullable|numeric|min:0',
            'warning_letter_3' => 'nullable|numeric|min:0',
            'subordinate_late' => 'nullable|numeric|min:0',
            'subordinate_awol' => 'nullable|numeric|min:0',
        ];
    }
}
