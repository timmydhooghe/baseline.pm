<?php

namespace App\Http\Requests\Dependencies;

use App\Enums\DependencyEventType;
use App\Models\Dependency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDependencyEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dependency = $this->route('dependency');

        return $dependency instanceof Dependency
            && ($this->user()?->can('update', $dependency) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * An evidence trail entry says what happened, through which channel, and
     * when (FA-20) — the date is typed because a chase two weeks ago and one
     * this morning defend a slip very differently.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DependencyEventType::class)],
            'channel' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'evidence_url' => ['nullable', 'url', 'max:2048'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'occurred_at.before_or_equal' => __('An evidence trail records what happened, not what is planned.'),
        ];
    }
}
