<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
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
        return [
            'name' => 'required|string|max:255',
            'employee_code' => 'nullable|string|unique:employees,employee_code',
            'email' => 'nullable|email|unique:employees,email',
            'position_id' => 'required|exists:positions,id',
            'join_date' => 'required|date',
            'end_contract_date' => 'nullable|date|after_or_equal:join_date',
            'is_active' => 'boolean',
            
            // Validasi Array Departments (Pivot)
            'departments' => 'array',
            'departments.*.id' => 'exists:departments,id',
            'departments.*.is_primary' => 'boolean',

            // Validasi Array Managers (Pivot)
            'managers' => 'array',
            'managers.*.id' => 'exists:employees,id',
            'managers.*.reporting_type' => 'in:direct,project,functional'
        ];
    }
}
