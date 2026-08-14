<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employeeId = $this->route('employee'); 

        return [
            'name' => 'required|string|max:255',
            'employee_code' => [
                'nullable', 
                'string', 
                Rule::unique('employees')->ignore($employeeId)
            ],
            'email' => [
                'nullable', 
                'email', 
                Rule::unique('employees')->ignore($employeeId)
            ],
            'position_id' => 'required|exists:positions,id',
            'join_date' => 'required|date',
            'end_contract_date' => 'nullable|date|after_or_equal:join_date',
            'is_active' => 'boolean',
            
            'departments' => 'array',
            'departments.*.id' => 'exists:departments,id',
            'departments.*.is_primary' => 'boolean',

            'managers' => 'array',
            'managers.*.id' => 'exists:employees,id',
            'managers.*.reporting_type' => 'in:direct,project,functional'
        ];
    }
}
