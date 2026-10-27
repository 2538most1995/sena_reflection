<?php

namespace App\Http\Requests;

use App\Services\AcademicTermService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeachingDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'source_record_id' => ['nullable', 'string', 'max:20'],
            'refresh_source' => ['sometimes', 'boolean'],
            'payload' => ['nullable', 'array'],
            ...self::payloadRules('payload.'),
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function payloadRules(string $prefix = ''): array
    {
        return [
            $prefix.'schema_version' => ['nullable', 'integer', 'in:1'],
            $prefix.'scope' => ['nullable', 'array'],
            $prefix.'scope.tambon' => ['nullable', 'string', 'max:20'],
            $prefix.'scope.week' => ['nullable', 'integer', 'between:1,20'],
            $prefix.'scope.date' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'scope.semester' => ['nullable', Rule::in(['1', '2', '3'])],
            $prefix.'scope.year' => ['string', ...AcademicTermService::yearRules(false)],
            $prefix.'active_level_code' => ['nullable', Rule::in(['1', '2', '3'])],
            $prefix.'enabled_level_codes' => ['sometimes', 'required', 'array', 'min:1', 'max:3'],
            $prefix.'enabled_level_codes.*' => ['required', 'distinct', Rule::in(['1', '2', '3'])],
            $prefix.'levels' => ['nullable', 'array', 'max:3'],
            $prefix.'levels.*' => ['array'],
            $prefix.'levels.*.subject' => ['nullable', 'string', 'max:150'],
            $prefix.'levels.*.subject_code' => ['nullable', 'string', 'max:50'],
            $prefix.'levels.*.class_group_code' => ['nullable', 'string', 'max:50'],
            $prefix.'levels.*.class_name' => ['nullable', 'string', 'max:255'],
            $prefix.'levels.*.total' => ['nullable', 'integer', 'between:0,10000'],
            $prefix.'levels.*.present' => ['nullable', 'integer', 'between:0,10000'],
            $prefix.'levels.*.result' => ['nullable', 'string', 'max:10000'],
            $prefix.'levels.*.problem' => ['nullable', 'string', 'max:10000'],
            $prefix.'levels.*.suggestion' => ['nullable', 'string', 'max:10000'],
            $prefix.'levels.*.supervisor_comment' => ['nullable', 'string', 'max:10000'],
            $prefix.'levels.*.attendance_reviewed' => ['nullable', 'boolean'],
            $prefix.'levels.*.attendance_mode' => ['nullable', Rule::in(['roster', 'counts'])],
            $prefix.'levels.*.attendance_term' => ['nullable', 'string', 'regex:/^[123]\/25\d{2}$/'],
            $prefix.'levels.*.attendance' => ['nullable', 'array', 'max:2000'],
            $prefix.'levels.*.attendance.*.student_id' => ['required', 'string', 'max:50', 'distinct'],
            $prefix.'levels.*.attendance.*.full_name' => ['required', 'string', 'max:255'],
            $prefix.'levels.*.attendance.*.group_code' => ['nullable', 'string', 'max:50'],
            $prefix.'levels.*.attendance.*.class_name' => ['nullable', 'string', 'max:255'],
            $prefix.'levels.*.attendance.*.present' => ['required', 'boolean'],
            $prefix.'levels.*.attendance.*.learning_method' => ['nullable', Rule::in(['พบกลุ่ม', 'ออนไลน์'])],
            $prefix.'levels.*.image_ids' => ['nullable', 'array', 'max:4'],
            $prefix.'levels.*.image_ids.*' => ['uuid', 'distinct'],
            $prefix.'levels.*.document' => ['nullable', 'array'],
            $prefix.'levels.*.document.settings' => ['nullable', 'array'],
            $prefix.'levels.*.document.settings.*' => ['nullable', 'string', 'max:10000'],
            $prefix.'levels.*.document.content' => ['nullable', 'array'],
            $prefix.'levels.*.document.content.*' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
