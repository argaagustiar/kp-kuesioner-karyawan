<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['start_date', 'end_date', 'description', 'evaluation_locked'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'evaluation_locked' => 'boolean',
    ];

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function evaluationTemplates(): HasMany
    {
        return $this->hasMany(EvaluationTemplate::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function evaluationAnswers(): HasMany
    {
        return $this->hasMany(EvaluationAnswer::class);
    }


}