<?php

namespace App\Http\Requests\Baselines;

use App\Models\Baseline;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcknowledgeBaselineCheckRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $baseline = $this->route('baseline');

        return $baseline instanceof Baseline
            && ($this->user()?->can('update', $baseline) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'check' => ['required', 'string', Rule::in(Baseline::CHECK_KEYS)],
        ];
    }
}
