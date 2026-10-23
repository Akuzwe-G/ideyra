<?php

namespace App\Support;

use App\Models\AcademicClass;
use App\Models\AcademicYear;
use App\Models\Institution;
use App\Models\Intake;
use App\Models\Programme;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicWorkflow
{
    public const TYPES = ['years' => AcademicYear::class, 'intakes' => Intake::class, 'programmes' => Programme::class, 'classes' => AcademicClass::class];

    public static function permission(string $type): string
    {
        return 'academic.'.$type;
    }

    public function validate(Request $request, Institution $institution, string $type, ?int $id = null): array
    {
        $model = new (self::TYPES[$type]);
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique($model->getTable())->where('institution_id', $institution->id)->ignore($id)],
            'status' => ['required', Rule::in(['draft', 'active', 'completed', 'archived'])],
        ];
        if (in_array($type, ['years', 'intakes'], true)) {
            $rules += ['starts_at' => ['required', 'date'], 'ends_at' => [$type === 'years' ? 'required' : 'nullable', 'date', 'after:starts_at']];
        }
        if ($type === 'programmes') {
            $rules += ['code' => ['required', 'alpha_dash', 'max:40', Rule::unique('programmes')->where('institution_id', $institution->id)->ignore($id)], 'description' => ['nullable', 'string', 'max:3000']];
        }
        foreach (match ($type) {
            'intakes' => ['academic_year_id' => 'academic_years'], 'classes' => ['academic_year_id' => 'academic_years', 'programme_id' => 'programmes', 'intake_id' => 'intakes'], default => []
        } as $field => $table) {
            $rules[$field] = ['required', 'integer', Rule::exists($table, 'id')->where('institution_id', $institution->id)];
        }
        if ($type === 'classes') {
            $rules['graduation_year'] = ['nullable', 'integer', 'between:1900,2200'];
        }
        $data = $request->validate($rules);
        if ($type === 'classes') {
            abort_unless(Intake::whereKey($data['intake_id'])->where('academic_year_id', $data['academic_year_id'])->exists(), 422, 'The class academic year must match its intake.');
        }

        return $data;
    }
}
