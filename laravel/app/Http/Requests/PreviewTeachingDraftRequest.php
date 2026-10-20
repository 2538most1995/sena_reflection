<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewTeachingDraftRequest extends FormRequest
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
            'lock_version' => ['required', 'integer', 'min:1'],
            'level_code' => ['required', Rule::in(['1', '2', '3'])],
            'document' => ['nullable', 'array'],
            'document.settings' => ['nullable', 'array'],
            'document.settings.*' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
