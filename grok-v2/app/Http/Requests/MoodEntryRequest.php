<?php

namespace App\Http\Requests;

use App\Domain\Mood\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoodEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'period' => ['required', Rule::enum(Period::class)],
            'mood' => ['required', 'integer', 'between:-3,3'],
            'energy' => ['nullable', 'integer', 'between:-3,3'],
            'anxiety' => ['nullable', 'integer', 'between:0,3'],
            'irritability' => ['nullable', 'integer', 'between:0,3'],
            'sleep_hours' => ['nullable', 'numeric', 'between:0,24'],
            'medication_taken' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:5000'],
            'client_uuid' => ['nullable', 'uuid'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** Somente campos de dominio (nunca tenant_id/patient_id do cliente). */
    public function entryData(): array
    {
        return collect($this->validated())->except('version')->all();
    }
}
