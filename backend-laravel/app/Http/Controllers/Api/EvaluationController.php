<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\StoreEvaluationRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Requests\UpdateEvaluationRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\EvaluationResource;
use App\Models\Evaluation;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class EvaluationController extends Controller
{
    public function index(Request $request)
    {
        $query = Evaluation::with(['period', 'employee', 'evaluator']);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->WhereHas('employee', function($posQuery) use ($search) {
                      $posQuery->where('name', 'ilike', "%{$search}%");
                  });
            });
            
        }

        if ($request->has('period_id')) {
            $query->where('period_id', $request->period_id);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('evaluator_id')) {
            $query->where('evaluator_id', $request->evaluator_id);
        }

        // Pagination
        $evaluation = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 10));

        return EvaluationResource::collection($evaluation);
    }

    public function store(StoreEvaluationRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $evaluation = Evaluation::create($request->validated());

            $evaluation->load(['period', 'employee', 'evaluator']);

            return new EvaluationResource($evaluation);
        });
    }

    public function show($id)
    {
        $evaluation = Evaluation::with(['period', 'employee', 'evaluator'])->findOrFail($id);
        return new EvaluationResource($evaluation);
    }

    public function update(UpdateEvaluationRequest $request, $id)
    {
        $evaluation = Evaluation::findOrFail($id);

        return DB::transaction(function () use ($request, $evaluation) {
            $evaluation->update($request->validated());

            $evaluation->refresh()->load(['period', 'employee', 'evaluator']);
            return new EvaluationResource($evaluation);
        });
    }

    public function destroy($id)
    {
        $evaluation = Evaluation::findOrFail($id);
        
        // Soft Delete (karena di model sudah pakai SoftDeletes)
        // Relasi pivot otomatis aman karena kita pakai ->wherePivotNull di Model
        $evaluation->delete();

        return response()->json(['message' => 'Evaluation deleted successfully']);
    }

    // --- REPORTS ---
    public function evaluationSummary(Request $request)
    {
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 400);
        }

        // Scoring points untuk attendance
        $POINTS = [
            'attendance' => [
                'sick' => -0.1,
                'work_accident' => -0.5,
                'permit' => -0.5,
                'awol' => -5.0,
                'late_permit' => -0.25,
                'early_leave' => -0.25,
                'annual_leave' => -0.1,
                'late' => -3.0,
            ],
            'warning' => [
                'first' => -5,
                'second' => -10,
                'third' => -15,
            ],
            'dept_head' => [
                'sub_late' => -1,
                'sub_awol' => -2,
            ],
        ];

        $summary = Evaluation::select(
            'id',
            'employee_id',
            'period_id',
            'evaluator_id',
            'question_1',
            'question_2',
            'question_3',
            'question_4',
            'question_5',
            'question_6',
            'question_7',
            'question_8',
            'question_9',
            'question_10',
        )
        ->where('period_id', $periodId)
        // ->groupBy('employee_id')
        ->with(['employee', 'employee.department', 'employee.position', 'employee.heads', 'employee.subordinates', 'employee.coworkers', 'period', 'evaluator']) // Eager load employee data
        ->get();

        $dataGroupedByEmployee = $summary->groupBy('employee_id')->map(function ($evaluations, $employeeId) use ($POINTS) {
            $employee = $evaluations->first()->employee;
            $period = $evaluations->first()->period;

            // index evaluator ids by relation type using the preloaded relationships
            $subsIds = $employee->subordinates->pluck('id')->all();
            $headsIds = $employee->heads->pluck('id')->all();
            $coworkerIds = $employee->coworkers->pluck('id')->all();

            // Hitung rata-rata total (semua pertanyaan digabung) untuk setiap kategori
            $calcOverall = function ($subset) {
                if ($subset->isEmpty()) {
                    return null;
                }
                // hitung total skor per evaluasi, lalu ambil rata-rata form
                return round(
                    $subset->map(function ($e) {
                        return (
                            $e->question_1 + $e->question_2 + $e->question_3 +
                            $e->question_4 + $e->question_5 + $e->question_6 +
                            $e->question_7 + $e->question_8 + $e->question_9 +
                            $e->question_10
                        );
                    })->avg(),
                    2
                );
            };

            $averageScores = [
                'subordinates' => $calcOverall($evaluations->whereIn('evaluator_id', $subsIds)),
                'heads'        => $calcOverall($evaluations->whereIn('evaluator_id', $headsIds)),
                'coworkers'    => $calcOverall($evaluations->whereIn('evaluator_id', $coworkerIds)),
                'self'         => $calcOverall($evaluations->where('evaluator_id', $employee->id)),
                'head_self'     => $calcOverall($evaluations->whereIn('evaluator_id', [...$headsIds, $employee->id])),
            ];

            // Load attendance record untuk periode ini
            $attendanceRecord = $employee->attendanceRecords()
                ->where('period_id', $period->id)
                ->first();

            // Hitung attendance score
            $attendanceScore = 0; // Start from 100
            $attendanceScoreDetails = [];
            if ($attendanceRecord) {
                $attendanceScoreDetails = [
                    'sick' => $attendanceRecord->sick * $POINTS['attendance']['sick'],
                    'work_accident' => $attendanceRecord->work_accident * $POINTS['attendance']['work_accident'],
                    'permit' => $attendanceRecord->permit * $POINTS['attendance']['permit'],
                    'awol' => $attendanceRecord->awol * $POINTS['attendance']['awol'],
                    'late_permit' => $attendanceRecord->late_permit * $POINTS['attendance']['late_permit'],
                    'early_leave' => $attendanceRecord->early_leave * $POINTS['attendance']['early_leave'],
                    'annual_leave' => $attendanceRecord->annual_leave * $POINTS['attendance']['annual_leave'],
                    'late' => $attendanceRecord->late * $POINTS['attendance']['late'],
                    'warning_letter_1' => $attendanceRecord->warning_letter_1 * $POINTS['warning']['first'],
                    'warning_letter_2' => $attendanceRecord->warning_letter_2 * $POINTS['warning']['second'],
                    'warning_letter_3' => $attendanceRecord->warning_letter_3 * $POINTS['warning']['third'],
                    'subordinate_late' => $attendanceRecord->subordinate_late * $POINTS['dept_head']['sub_late'],
                    'subordinate_awol' => $attendanceRecord->subordinate_awol * $POINTS['dept_head']['sub_awol'],
                ];

                // Attendance deductions
                $attendanceScore += $attendanceRecord->sick * $POINTS['attendance']['sick'];
                $attendanceScore += $attendanceRecord->work_accident * $POINTS['attendance']['work_accident'];
                $attendanceScore += $attendanceRecord->permit * $POINTS['attendance']['permit'];
                $attendanceScore += $attendanceRecord->awol * $POINTS['attendance']['awol'];
                $attendanceScore += $attendanceRecord->late_permit * $POINTS['attendance']['late_permit'];
                $attendanceScore += $attendanceRecord->early_leave * $POINTS['attendance']['early_leave'];
                $attendanceScore += $attendanceRecord->annual_leave * $POINTS['attendance']['annual_leave'];
                $attendanceScore += $attendanceRecord->late * $POINTS['attendance']['late'];

                // Warning letter deductions
                $attendanceScore += $attendanceRecord->warning_letter_1 * $POINTS['warning']['first'];
                $attendanceScore += $attendanceRecord->warning_letter_2 * $POINTS['warning']['second'];
                $attendanceScore += $attendanceRecord->warning_letter_3 * $POINTS['warning']['third'];

                // Department head deductions (only if user is a manager)
                $attendanceScore += $attendanceRecord->subordinate_late * $POINTS['dept_head']['sub_late'];
                $attendanceScore += $attendanceRecord->subordinate_awol * $POINTS['dept_head']['sub_awol'];
            }

            return [
                'employee' => new EmployeeResource($employee),
                'period' => $period,
                'average_scores' => $averageScores,
                'attendance_record' => $attendanceRecord ? [
                    'id' => $attendanceRecord->id,
                    'sick' => $attendanceRecord->sick,
                    'work_accident' => $attendanceRecord->work_accident,
                    'permit' => $attendanceRecord->permit,
                    'awol' => $attendanceRecord->awol,
                    'late_permit' => $attendanceRecord->late_permit,
                    'early_leave' => $attendanceRecord->early_leave,
                    'annual_leave' => $attendanceRecord->annual_leave,
                    'late' => $attendanceRecord->late,
                    'warning_letter_1' => $attendanceRecord->warning_letter_1,
                    'warning_letter_2' => $attendanceRecord->warning_letter_2,
                    'warning_letter_3' => $attendanceRecord->warning_letter_3,
                    'subordinate_late' => $attendanceRecord->subordinate_late,
                    'subordinate_awol' => $attendanceRecord->subordinate_awol,
                ] : null,
                'attendance_score' => $attendanceRecord ? round($attendanceScore, 2) : null,
                'attendance_score_details' => $attendanceScoreDetails,
            ];
        });

        return response()->json($dataGroupedByEmployee->values());
    }

    public function commentsSummary(Request $request)
    {
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 400);
        }

        $summary = Evaluation::select(
            'id',
            'employee_id',
            'period_id',
            'evaluator_id',
            'comments'
        )
        ->where('period_id', $periodId)
        // ->groupBy(['id', 'employee_id'])
        ->with(['employee:id,name,department_id,position_id', 'employee.department', 'employee.position', 'evaluator:id,name'])
        ->get();

        $dataGroupedByEmployee = $summary->groupBy('employee_id')->map(function ($evaluations, $employeeId) {
            $employee = $evaluations->first()->employee;
            $period = $evaluations->first()->period;

            $comments = [];
            foreach ($evaluations as $eval) {
                $comments[] = [
                    'evaluator_name' => $eval->evaluator->name ?? 'Unknown',
                    'comment' => $eval->comments,
                ];
            }

            return [
                'employee_id' => $employee->employee_code ?? $employee->id,
                'full_name' => $employee->name,
                'organization' => $employee->department->name ?? '',
                'job_position' => $employee->position->title ?? '',
                'comments' => $comments,
            ];
        });

        return response()->json($dataGroupedByEmployee->values());
    }

    public function exportEvaluationSummary(Request $request)
    {
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 400);
        }

        $period = Period::find($periodId);

        $startDateMonth = $period->start_date->format('F');
        $isSecondHalfFY = $startDateMonth === 'March' ? true : false;
        
        $dataGroupedByEmployee = $this->evaluationSummaryByPeriodId($periodId);
        $dataGroupedByEmployeeFirstHalf = [];

        if ($isSecondHalfFY) {
            $firstHalfEndDate = $period->start_date->copy()->subDay();
            $firstHalfPeriod = Period::where('end_date', $firstHalfEndDate)->first();
            if ($firstHalfPeriod) {
                $dataGroupedByEmployeeFirstHalf = $this->evaluationSummaryByPeriodId($firstHalfPeriod->id);
            }
        }

        foreach ($dataGroupedByEmployeeFirstHalf as $employeeId => $data) {
            $dataGroupedByEmployee[$employeeId]['percent_1st_half'] = $data['percent_2nd_half'];

            if ($isSecondHalfFY && isset($dataGroupedByEmployeeFirstHalf[$employeeId])) {
                $avgFY = ($dataGroupedByEmployee[$employeeId]['percent_2nd_half'] + $dataGroupedByEmployee[$employeeId]['percent_1st_half']) / 2;
                $dataGroupedByEmployee[$employeeId]['avg_percent_fy'] = round($avgFY, 1);
            }
        };

        // detail headings for each column
        $detailHeadings = [
            'Employee ID','Full Name','Organization','Job Position','Join Date','Regular Employee','Work Tenur','PROMOTION TO',
            'SICK LEAVE','WORK ACCIDENT','PERMIT DO NOT HAVE ANNUAL LEAVE','ABSENT /AWOL','PERMIT OF LATE','PERMIT TO EARLY GO HOME','ANNUAL LEAVE','LATE',
            'WARNING LETTER - 1','WARNING LETTER - 2','WARNING LETTER - 3','SUB ORDINATE ARE LATE','SUB ORDINATE ARE AWOL','TOTAL',
            'POINT EVALUATION Per Form (from Superior & Director)','TOTAL POINT EVALUATION Per Form (from Superior & Director)','POINT EVALUATION (coworker)','POINT EVALUATION FOR LEADER UP (from Subordinate)','TOTAL AVERAGE POINT PER EVALUATION (2nd Half FY)',
            'PERCENTAGE RATING IN EVALUATION 2nd  Half FY','PERCENTAGE RATING IN EVALUATION 1st Half FY','AVERAGE PERCENTAGE RATING IN EVALUATION FY 14','MERIT RATING',
            'SICK LEAVE','WORK ACCIDENT','PERMIT DO NOT HAVE ANNUAL LEAVE','ABSENT /AWOL','PERMIT OF LATE','PERMIT TO EARLY GO HOME','ANNUAL LEAVE','LATE',
            'WARNING LETTER - 1','WARNING LETTER - 2','WARNING LETTER - 3','SUB ORDINATE ARE LATE','SUB ORDINATE ARE AWOL',
            'SICK LEAVE','WORK ACCIDENT','PERMIT DO NOT HAVE ANNUAL LEAVE','ABSENT /AWOL','PERMIT OF LATE','PERMIT TO EARLY GO HOME','ANNUAL LEAVE','LATE',
            'WARNING LETTER - 1','WARNING LETTER - 2','WARNING LETTER - 3','SUB ORDINATE ARE LATE','SUB ORDINATE ARE AWOL'
        ];

        // define group headings with their column ranges (1-based): [group_name => [start_col, end_col]]
        $groupHeadings = [
            'DEDUCTION' => [9, 21],           // SICK LEAVE to TOTAL (columns I to U)
            'TIMES/DAY' => [32, 44],          // TIMES columns (columns AF to AR)
            'POINTS' => [45, 57],             // POINTS columns (columns AS to BE)
        ];

        $rows = collect($dataGroupedByEmployee)->map(function ($row) {
            // flatten to list by heading order
            return [
                $row['employee_id'],
                $row['full_name'],
                $row['organization'],
                $row['job_position'],
                $row['join_date'],
                $row['regular_employee'],
                $row['work_tenure'],
                $row['promotion_to'],
                $row['sick_leave'],
                $row['work_accident'],
                $row['permit_no_annual'],
                $row['absent_awol'],
                $row['permit_late'],
                $row['permit_early'],
                $row['annual_leave'],
                $row['late'],
                $row['warning_1'],
                $row['warning_2'],
                $row['warning_3'],
                $row['sub_late'],
                $row['sub_awol'],
                $row['attendance_total'],
                $row['eval_superior_1'],
                $row['eval_superior_2'],
                $row['eval_coworker'],
                $row['eval_leader_up'],
                $row['avg_eval'],
                $row['percent_2nd_half'],
                $row['percent_1st_half'],
                $row['avg_percent_fy'],
                $row['merit_rating'],
                $row['times_sick_leave'],
                $row['times_work_accident'],
                $row['times_permit_no_annual'],
                $row['times_absent_awol'],
                $row['times_permit_late'],
                $row['times_permit_early'],
                $row['times_annual_leave'],
                $row['times_late'],
                $row['times_warning_1'],
                $row['times_warning_2'],
                $row['times_warning_3'],
                $row['times_sub_late'],
                $row['times_sub_awol'],
                $row['point_sick_leave'],
                $row['point_work_accident'],
                $row['point_permit_no_annual'],
                $row['point_absent_awol'],
                $row['point_permit_late'],
                $row['point_permit_early'],
                $row['point_annual_leave'],
                $row['point_late'],
                $row['point_warning_1'],
                $row['point_warning_2'],
                $row['point_warning_3'],
                $row['point_sub_late'],
                $row['point_sub_awol'],
            ];
        });

        // use PhpSpreadsheet directly
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // write group headings in row 1 with merged cells
        foreach ($groupHeadings as $groupName => [$startCol, $endCol]) {
            $startColLetter = Coordinate::stringFromColumnIndex($startCol);
            $endColLetter = Coordinate::stringFromColumnIndex($endCol);
            $mergeRange = $startColLetter.'1:'.$endColLetter.'1';
            $sheet->mergeCells($mergeRange);
            $sheet->setCellValue($startColLetter.'1', $groupName);
        }
        
        // write detail headings in row 2
        for ($i = 0; $i < count($detailHeadings); $i++) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter.'2', $detailHeadings[$i]);
        }
        
        // write data starting from row 3
        $rowNum = 3;
        foreach ($rows as $row) {
            for ($i = 0; $i < count($row); $i++) {
                $colLetter = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue($colLetter.$rowNum, $row[$i]);
            }
            $rowNum++;
        }

        // --- TAMBAHKAN KODE STYLING BORDER DI SINI ---
        
        // 1. Dapatkan kolom terakhir dan baris terakhir
        $lastColumnLetter = Coordinate::stringFromColumnIndex(count($detailHeadings));
        $lastRow = $rowNum - 1;
        $tableRange = 'A2:' . $lastColumnLetter . $lastRow;
        $headerRange1 = 'I1:U1';
        $headerRange2 = 'AF1:BE1';

        // 2. Terapkan border ke seluruh tabel
        $sheet->getStyle($headerRange1)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'], // Warna hitam
                ],
            ],
        ]);
        $sheet->getStyle($headerRange2)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'], // Warna hitam
                ],
            ],
        ]);

        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'], // Warna hitam
                ],
            ],
        ]);

        // 3. (Opsional) Bikin text Header (baris 1 dan 2) jadi tebal dan center agar lebih rapi
        $headerRange = 'A1:' . $lastColumnLetter . '2';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // prepare streaming response
        $filename = 'evaluation-summary.xlsx';

        return response()->streamDownload(function() use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function exportCommentsSummary(Request $request)
    {
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 400);
        }

        $summary = Evaluation::select(
            'employee_id',
            'evaluator_id',
            'comments'
        )
        ->where('period_id', $periodId)
        ->with(['employee:id,name,department_id,position_id', 'employee.department', 'employee.position', 'evaluator:id,name'])
        ->get();

        $dataGroupedByEmployee = $summary->groupBy('employee_id')->map(function ($evaluations, $employeeId) {
            $employee = $evaluations->first()->employee;
            $period = $evaluations->first()->period;

            $comments = [];
            foreach ($evaluations as $eval) {
                $comments[] = [
                    'evaluator_name' => $eval->evaluator->name ?? 'Unknown',
                    'comment' => $eval->comments,
                ];
            }

            return [
                'employee_id' => $employee->employee_code ?? $employee->id,
                'full_name' => $employee->name,
                'organization' => $employee->department->name ?? '',
                'job_position' => $employee->position->title ?? '',
                'comments' => $comments,
            ];
        });

        $headings = [
            'NO', 'NAME', 'DEPARTMENT', 'TITLE', 'COMMENTS', 'EVALUATOR',
        ];

        $rows = [];
        $no = 1;

        foreach ($dataGroupedByEmployee as $employeeData) {
            $firstComment = true;
            foreach ($employeeData['comments'] as $c) {
                $rows[] = [
                    'no' => $firstComment ? $no : '', // No hanya di baris pertama karyawan
                    'name' => $firstComment ? $employeeData['full_name'] : '',
                    'department' => $firstComment ? $employeeData['organization'] : '',
                    'title' => $firstComment ? $employeeData['job_position'] : '',
                    'comment' => $c['comment'],
                    'evaluator' => $c['evaluator_name'],
                ];
                $firstComment = false;
            }
            // $no++;
        }

        // use PhpSpreadsheet directly
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
                
        // write detail headings in row 2
        for ($i = 0; $i < count($headings); $i++) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter.'2', $headings[$i]);
        }
        
        // write data starting from row 3
        $rowNum = 3;
        foreach ($dataGroupedByEmployee as $employeeData) {
            $startRow = $rowNum; // Catat baris awal karyawan ini
            
            foreach ($employeeData['comments'] as $index => $c) {
                // Isi data per baris
                $sheet->setCellValue('A' . $rowNum, $index === 0 ? $no : '');
                $sheet->setCellValue('B' . $rowNum, $index === 0 ? $employeeData['full_name'] : '');
                $sheet->setCellValue('C' . $rowNum, $index === 0 ? $employeeData['organization'] : '');
                $sheet->setCellValue('D' . $rowNum, $index === 0 ? $employeeData['job_position'] : '');
                $sheet->setCellValue('E' . $rowNum, $c['comment']);
                $sheet->setCellValue('F' . $rowNum, $c['evaluator_name']);
                
                // Tambahkan Wrap Text untuk kolom Comments (E) agar rapi
                $sheet->getStyle('E' . $rowNum)->getAlignment()->setWrapText(true);
                
                $rowNum++;
            }
            
            $endRow = $rowNum - 1; // Baris terakhir untuk karyawan ini

            // Lakukan Merge Vertical dari kolom A sampai D jika komentar lebih dari satu
            if ($startRow < $endRow) {
                foreach (['A', 'B', 'C', 'D'] as $col) {
                    $sheet->mergeCells($col . $startRow . ':' . $col . $endRow);
                }
            }

            // Set Center Alignment (Horizontal & Vertikal) untuk kolom yang di-merge
            $sheet->getStyle('A' . $startRow . ':D' . $endRow)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
            
            // Khusus kolom Evaluator (F) juga dibuat Center secara vertikal
            $sheet->getStyle('F' . $startRow . ':F' . $endRow)->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER);

            $no++;
        }

        // --- TAMBAHKAN KODE STYLING BORDER DI SINI ---
        
        // 1. Dapatkan kolom terakhir dan baris terakhir
        $lastColumnLetter = Coordinate::stringFromColumnIndex(count($headings));
        $lastRow = $rowNum - 1;
        $tableRange = 'A2:' . $lastColumnLetter . $lastRow;

        // 2. Terapkan border ke seluruh tabel
        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'], // Warna hitam
                ],
            ],
        ]);

        // 3. (Opsional) Bikin text Header (baris 1 dan 2) jadi tebal dan center agar lebih rapi
        $headerRange = 'A2:' . $lastColumnLetter . '2';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // prepare streaming response
        $filename = 'comments-summary.xlsx';

        return response()->streamDownload(function() use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    protected function evaluationSummaryByPeriodId($periodId)
    {
        // reuse summary query from evaluationSummary above
        $POINTS = [
            'attendance' => [
                'sick' => -0.1,
                'work_accident' => -0.5,
                'permit' => -0.5,
                'awol' => -5.0,
                'late_permit' => -0.25,
                'early_leave' => -0.25,
                'annual_leave' => -0.1,
                'late' => -3.0,
            ],
            'warning' => [
                'first' => -5,
                'second' => -10,
                'third' => -15,
            ],
            'dept_head' => [
                'sub_late' => -1,
                'sub_awol' => -2,
            ],
        ];

        $summary = Evaluation::select(
            'id',
            'employee_id',
            'period_id',
            'evaluator_id',
            'question_1',
            'question_2',
            'question_3',
            'question_4',
            'question_5',
            'question_6',
            'question_7',
            'question_8',
            'question_9',
            'question_10',
        )
        ->where('period_id', $periodId)
        ->with(['employee', 'employee.department', 'employee.position', 'employee.heads', 'employee.subordinates', 'employee.coworkers', 'period', 'evaluator'])
        ->get();

        $dataGroupedByEmployee = $summary->groupBy('employee_id')->map(function ($evaluations, $employeeId) use ($POINTS) {
            $employee = $evaluations->first()->employee;
            $period = $evaluations->first()->period;

            $subsIds = $employee->subordinates->pluck('id')->all();
            $headsIds = $employee->heads->pluck('id')->all();
            $coworkerIds = $employee->coworkers->pluck('id')->all();

            $calcOverall = function ($subset) {
                if ($subset->isEmpty()) {
                    return null;
                }
                return round(
                    $subset->map(function ($e) {
                        return (
                            $e->question_1 + $e->question_2 + $e->question_3 +
                            $e->question_4 + $e->question_5 + $e->question_6 +
                            $e->question_7 + $e->question_8 + $e->question_9 +
                            $e->question_10
                        );
                    })->avg(),
                    2
                );
            };

            $averageScores = [
                'subordinates' => $calcOverall($evaluations->whereIn('evaluator_id', $subsIds)),
                'heads'        => $calcOverall($evaluations->whereIn('evaluator_id', $headsIds)),
                'coworkers'    => $calcOverall($evaluations->whereIn('evaluator_id', $coworkerIds)),
                'self'         => $calcOverall($evaluations->where('evaluator_id', $employee->id)),
                'head_self'    => $calcOverall($evaluations->whereIn('evaluator_id', [...$headsIds, $employee->id])),
            ];

            $attendanceRecord = $employee->attendanceRecords()
                ->where('period_id', $period->id)
                ->first();

            // compute attendance score details again
            $attendanceScore = 0;
            $attendanceScoreDetails = [];
            if ($attendanceRecord) {
                $attendanceScoreDetails = [
                    'sick' => $attendanceRecord->sick * $POINTS['attendance']['sick'],
                    'work_accident' => $attendanceRecord->work_accident * $POINTS['attendance']['work_accident'],
                    'permit' => $attendanceRecord->permit * $POINTS['attendance']['permit'],
                    'awol' => $attendanceRecord->awol * $POINTS['attendance']['awol'],
                    'late_permit' => $attendanceRecord->late_permit * $POINTS['attendance']['late_permit'],
                    'early_leave' => $attendanceRecord->early_leave * $POINTS['attendance']['early_leave'],
                    'annual_leave' => $attendanceRecord->annual_leave * $POINTS['attendance']['annual_leave'],
                    'late' => $attendanceRecord->late * $POINTS['attendance']['late'],
                    'warning_letter_1' => $attendanceRecord->warning_letter_1 * $POINTS['warning']['first'],
                    'warning_letter_2' => $attendanceRecord->warning_letter_2 * $POINTS['warning']['second'],
                    'warning_letter_3' => $attendanceRecord->warning_letter_3 * $POINTS['warning']['third'],
                    'subordinate_late' => $attendanceRecord->subordinate_late * $POINTS['dept_head']['sub_late'],
                    'subordinate_awol' => $attendanceRecord->subordinate_awol * $POINTS['dept_head']['sub_awol'],
                ];

                $attendanceScore += array_sum($attendanceScoreDetails);
            }

            // prepare weighted evaluation values
            $eval_superior_1 = $averageScores['head_self'] ?? 0;
            $eval_superior_2 = round($attendanceScore + ($averageScores['head_self'] ?? 0), 2);
            $eval_coworker = $averageScores['coworkers'] ?? 0;
            $eval_leader_up = $averageScores['subordinates'] ?? null;

            // conditional weighted formula
            if (!empty($eval_leader_up)) {
                // leader-up exists: 75% superior_2, 10% coworker, 15% leader_up
                $avg_eval = round(($eval_superior_2 * 0.75) + ($eval_coworker * 0.10) + ($eval_leader_up * 0.15), 2);
            } else {
                // no leader-up: 90% superior_2, 10% coworker
                $avg_eval = round(($eval_superior_2 * 0.90) + ($eval_coworker * 0.10), 2);
            }

            return [
                'employee_id' => $employee->employee_code ?? $employee->id,
                'full_name' => $employee->name,
                'organization' => $employee->department->name ?? '',
                'job_position' => $employee->position->title ?? '',
                'join_date' => $employee->join_date?->format('d-m-Y') ?? '',
                'regular_employee' => '',
                'work_tenure' => $employee->join_date ? round($employee->join_date->diffInYears(now()), 2) : 0,
                'promotion_to' => '',
                // attendance columns
                'sick_leave' => $attendanceScoreDetails['sick'] ?? 0,
                'work_accident' => $attendanceScoreDetails['work_accident'] ?? 0,
                'permit_no_annual' => $attendanceScoreDetails['permit'] ?? 0,
                'absent_awol' => $attendanceScoreDetails['awol'] ?? 0,
                'permit_late' => $attendanceScoreDetails['late_permit'] ?? 0,
                'permit_early' => $attendanceScoreDetails['early_leave'] ?? 0,
                'annual_leave' => $attendanceScoreDetails['annual_leave'] ?? 0,
                'late' => $attendanceScoreDetails['late'] ?? 0,
                'warning_1' => $attendanceScoreDetails['warning_letter_1'] ?? 0,
                'warning_2' => $attendanceScoreDetails['warning_letter_2'] ?? 0,
                'warning_3' => $attendanceScoreDetails['warning_letter_3'] ?? 0,
                'sub_late' => $attendanceScoreDetails['subordinate_late'] ?? 0,
                'sub_awol' => $attendanceScoreDetails['subordinate_awol'] ?? 0,
                'attendance_total' => round($attendanceScore,2),
                'eval_superior_1' => $eval_superior_1,
                'eval_superior_2' => $eval_superior_2,
                'eval_coworker' => $eval_coworker,
                'eval_leader_up' => $eval_leader_up,
                'avg_eval' => $avg_eval,
                'attendance_score' => round($attendanceScore,2),
                // additional metric columns left blank
                'percent_2nd_half' => $avg_eval,
                'percent_1st_half' => '',
                'avg_percent_fy' => '',
                'merit_rating' => '',
                // duplicate attendance detail later may be omitted intentionally
                'times_sick_leave' => $attendanceRecord->sick ?? 0,
                'times_work_accident' => $attendanceRecord->work_accident ?? 0,
                'times_permit_no_annual' => $attendanceRecord->permit ?? 0,
                'times_absent_awol' => $attendanceRecord->awol ?? 0,
                'times_permit_late' => $attendanceRecord->late_permit ?? 0,
                'times_permit_early' => $attendanceRecord->early_leave ?? 0,
                'times_annual_leave' => $attendanceRecord->annual_leave ?? 0,
                'times_late' => $attendanceRecord->late ?? 0,
                'times_warning_1' => $attendanceRecord->warning_letter_1 ?? 0,
                'times_warning_2' => $attendanceRecord->warning_letter_2 ?? 0,
                'times_warning_3' => $attendanceRecord->warning_letter_3 ?? 0,
                'times_sub_late' => $attendanceRecord->subordinate_late ?? 0,
                'times_sub_awol' => $attendanceRecord->subordinate_awol ?? 0,
                'point_sick_leave' => $POINTS['attendance']['sick'] ?? 0,
                'point_work_accident' => $POINTS['attendance']['work_accident'] ?? 0,
                'point_permit_no_annual' => $POINTS['attendance']['permit'] ?? 0,
                'point_absent_awol' => $POINTS['attendance']['awol'] ?? 0,
                'point_permit_late' => $POINTS['attendance']['late_permit'] ?? 0,
                'point_permit_early' => $POINTS['attendance']['early_leave'] ?? 0,
                'point_annual_leave' => $POINTS['attendance']['annual_leave'] ?? 0,
                'point_late' => $POINTS['attendance']['late'] ?? 0,
                'point_warning_1' => $POINTS['attendance']['warning_letter_1'] ?? 0,
                'point_warning_2' => $POINTS['attendance']['warning_letter_2'] ?? 0,
                'point_warning_3' => $POINTS['attendance']['warning_letter_3'] ?? 0,
                'point_sub_late' => $POINTS['attendance']['subordinate_late'] ?? 0,
                'point_sub_awol' => $POINTS['attendance']['subordinate_awol'] ?? 0,
                
            ];
        });

        return $dataGroupedByEmployee;
    }

    public function evaluationBreakdown(Request $request)
    {
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 400);
        }

        // Get all evaluations for the period with detailed question answers
        $evaluations = Evaluation::select(
            'id',
            'employee_id',
            'evaluator_id',
            'question_1',
            'question_2',
            'question_3',
            'question_4',
            'question_5',
            'question_6',
            'question_7',
            'question_8',
            'question_9',
            'question_10',
        )
        ->where('period_id', $periodId)
        ->with(['employee', 'employee.department', 'employee.position', 'employee.heads', 'employee.subordinates', 'employee.coworkers', 'evaluator'])
        ->get();

        $dataGroupedByEmployee = $evaluations->groupBy('employee_id')->map(function ($evaluations) {
            $employee = $evaluations->first()->employee;
            $subsIds = $employee->subordinates->pluck('id')->all();
            $headsIds = $employee->heads->pluck('id')->all();
            $coworkerIds = $employee->coworkers->pluck('id')->all();

            // Helper function to determine evaluator type
            $getEvaluatorType = function ($evaluatorId) use ($subsIds, $headsIds, $coworkerIds, $employee) {
                if ($evaluatorId == $employee->id) {
                    return 'Self';
                } elseif (in_array($evaluatorId, $subsIds)) {
                    return 'Subordinate';
                } elseif (in_array($evaluatorId, $headsIds)) {
                    return 'Head';
                } elseif (in_array($evaluatorId, $coworkerIds)) {
                    return 'Coworker';
                }
                return 'Other';
            };

            // Map each evaluation to detail view
            $evaluationDetails = $evaluations->map(function ($eval) use ($getEvaluatorType) {
                return [
                    'evaluator_name' => $eval->evaluator->name ?? 'Unknown',
                    // 'evaluator_type' => $getEvaluatorType($eval->evaluator_id),
                    'question_1' => $eval->question_1,
                    'question_2' => $eval->question_2,
                    'question_3' => $eval->question_3,
                    'question_4' => $eval->question_4,
                    'question_5' => $eval->question_5,
                    'question_6' => $eval->question_6,
                    'question_7' => $eval->question_7,
                    'question_8' => $eval->question_8,
                    'question_9' => $eval->question_9,
                    'question_10' => $eval->question_10,
                ];
            })->values();

            return [
                'employee_id' => $employee->employee_code ?? $employee->id,
                'full_name' => $employee->name,
                'organization' => $employee->department->name ?? '',
                'job_position' => $employee->position->title ?? '',
                'total_evaluations' => $evaluations->count(),
                'evaluations' => $evaluationDetails,
            ];
        });

        return response()->json($dataGroupedByEmployee->values());
    }

    public function exportEvaluationBreakdown(Request $request)
    {
        $periodId = $request->query('period_id');

        if (!$periodId) {
            return response()->json(['message' => 'period_id is required'], 400);
        }

        // Get all evaluations for the period with detailed question answers
        $evaluations = Evaluation::select(
            'id',
            'employee_id',
            'evaluator_id',
            'question_1',
            'question_2',
            'question_3',
            'question_4',
            'question_5',
            'question_6',
            'question_7',
            'question_8',
            'question_9',
            'question_10',
        )
        ->where('period_id', $periodId)
        ->with(['employee', 'employee.department', 'employee.position', 'employee.heads', 'employee.subordinates', 'employee.coworkers', 'evaluator'])
        ->get();

        $dataGroupedByEmployee = $evaluations->groupBy('employee_id')->map(function ($evaluations) {
            $employee = $evaluations->first()->employee;
            $subsIds = $employee->subordinates->pluck('id')->all();
            $headsIds = $employee->heads->pluck('id')->all();
            $coworkerIds = $employee->coworkers->pluck('id')->all();

            // Helper function to determine evaluator type
            $getEvaluatorType = function ($evaluatorId) use ($subsIds, $headsIds, $coworkerIds, $employee) {
                if ($evaluatorId == $employee->id) {
                    return 'Self';
                } elseif (in_array($evaluatorId, $subsIds)) {
                    return 'Subordinate';
                } elseif (in_array($evaluatorId, $headsIds)) {
                    return 'Head';
                } elseif (in_array($evaluatorId, $coworkerIds)) {
                    return 'Coworker';
                }
                return 'Other';
            };

            $evaluationDetails = $evaluations->map(function ($eval) use ($getEvaluatorType) {
                return [
                    'evaluator_name' => $eval->evaluator->name ?? 'Unknown',
                    // 'evaluator_type' => $getEvaluatorType($eval->evaluator_id),
                    'question_1' => $eval->question_1,
                    'question_2' => $eval->question_2,
                    'question_3' => $eval->question_3,
                    'question_4' => $eval->question_4,
                    'question_5' => $eval->question_5,
                    'question_6' => $eval->question_6,
                    'question_7' => $eval->question_7,
                    'question_8' => $eval->question_8,
                    'question_9' => $eval->question_9,
                    'question_10' => $eval->question_10,
                ];
            })->values();

            return [
                'employee_id' => $employee->employee_code ?? $employee->id,
                'full_name' => $employee->name,
                'organization' => $employee->department->name ?? '',
                'job_position' => $employee->position->title ?? '',
                'total_evaluations' => $evaluations->count(),
                'evaluations' => $evaluationDetails,
            ];
        });

        $headings = [
            'NO', 'EMPLOYEE_ID', 'NAME', 'DEPARTMENT', 'POSITION', 'EVALUATOR', 
            //'EVALUATOR_TYPE', 
            'Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6', 'Q7', 'Q8', 'Q9', 'Q10',
        ];

        $rows = [];
        $no = 1;

        foreach ($dataGroupedByEmployee as $employeeData) {
            $firstEvaluation = true;
            foreach ($employeeData['evaluations'] as $eval) {
                $rows[] = [
                    'no' => $firstEvaluation ? $no : '',
                    'employee_id' => $firstEvaluation ? $employeeData['employee_id'] : '',
                    'name' => $firstEvaluation ? $employeeData['full_name'] : '',
                    'department' => $firstEvaluation ? $employeeData['organization'] : '',
                    'position' => $firstEvaluation ? $employeeData['job_position'] : '',
                    'evaluator' => $eval['evaluator_name'],
                    // 'evaluator_type' => $eval['evaluator_type'],
                    'q1' => $eval['question_1'],
                    'q2' => $eval['question_2'],
                    'q3' => $eval['question_3'],
                    'q4' => $eval['question_4'],
                    'q5' => $eval['question_5'],
                    'q6' => $eval['question_6'],
                    'q7' => $eval['question_7'],
                    'q8' => $eval['question_8'],
                    'q9' => $eval['question_9'],
                    'q10' => $eval['question_10'],
                ];
                $firstEvaluation = false;
            }
            $no++;
        }

        // use PhpSpreadsheet directly
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
                
        // write detail headings in row 1
        for ($i = 0; $i < count($headings); $i++) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter.'1', $headings[$i]);
        }
        
        // write data starting from row 2
        $rowNum = 2;
        foreach ($dataGroupedByEmployee as $employeeData) {
            $startRow = $rowNum; // Catat baris awal karyawan ini
            
            foreach ($employeeData['evaluations'] as $index => $eval) {
                // Isi data per baris
                $sheet->setCellValue('A' . $rowNum, $index === 0 ? count(array_filter(array_map(fn($r) => $r['no'], array_slice($rows, 0, array_search($rowNum - 2, array_column($rows, 'no')) + 1)))) : '');
                $sheet->setCellValue('B' . $rowNum, $index === 0 ? $employeeData['employee_id'] : '');
                $sheet->setCellValue('C' . $rowNum, $index === 0 ? $employeeData['full_name'] : '');
                $sheet->setCellValue('D' . $rowNum, $index === 0 ? $employeeData['organization'] : '');
                $sheet->setCellValue('E' . $rowNum, $index === 0 ? $employeeData['job_position'] : '');
                $sheet->setCellValue('F' . $rowNum, $eval['evaluator_name']);
                // $sheet->setCellValue('G' . $rowNum, $eval['evaluator_type']);
                $sheet->setCellValue('G' . $rowNum, $eval['question_1']);
                $sheet->setCellValue('H' . $rowNum, $eval['question_2']);
                $sheet->setCellValue('I' . $rowNum, $eval['question_3']);
                $sheet->setCellValue('J' . $rowNum, $eval['question_4']);
                $sheet->setCellValue('K' . $rowNum, $eval['question_5']);
                $sheet->setCellValue('L' . $rowNum, $eval['question_6']);
                $sheet->setCellValue('M' . $rowNum, $eval['question_7']);
                $sheet->setCellValue('N' . $rowNum, $eval['question_8']);
                $sheet->setCellValue('O' . $rowNum, $eval['question_9']);
                $sheet->setCellValue('P' . $rowNum, $eval['question_10']);
                
                $rowNum++;
            }
            
            $endRow = $rowNum - 1; // Baris terakhir untuk karyawan ini

            // Lakukan Merge Vertical dari kolom A sampai E jika evaluasi lebih dari satu
            if ($startRow < $endRow) {
                foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
                    $sheet->mergeCells($col . $startRow . ':' . $col . $endRow);
                }
            }

            // Set Center Alignment (Horizontal & Vertikal) untuk kolom yang di-merge
            $sheet->getStyle('A' . $startRow . ':E' . $endRow)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        // --- STYLING BORDER ---
        
        // 1. Dapatkan kolom terakhir dan baris terakhir
        $lastColumnLetter = Coordinate::stringFromColumnIndex(count($headings));
        $lastRow = $rowNum - 1;
        $tableRange = 'A1:' . $lastColumnLetter . $lastRow;

        // 2. Terapkan border ke seluruh tabel
        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'], // Warna hitam
                ],
            ],
        ]);

        // 3. Bikin text Header (baris 1) jadi tebal dan center agar lebih rapi
        $headerRange = 'A1:' . $lastColumnLetter . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Auto fit columns
        foreach (range('A', $lastColumnLetter) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // prepare streaming response
        $filename = 'evaluation-breakdown.xlsx';

        return response()->streamDownload(function() use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}