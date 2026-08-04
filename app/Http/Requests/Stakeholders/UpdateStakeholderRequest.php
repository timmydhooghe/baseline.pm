<?php

namespace App\Http\Requests\Stakeholders;

use App\Enums\StakeholderRole;
use App\Models\Stakeholder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStakeholderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $stakeholder = $this->route('stakeholder');

        return $stakeholder instanceof Stakeholder
            && ($this->user()?->can('update', $stakeholder) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $stakeholder = $this->route('stakeholder');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(Stakeholder::class)
                    ->where('organization_id', $this->user()?->organization_id)
                    ->ignore($stakeholder instanceof Stakeholder ? $stakeholder->id : null),
            ],
            'role' => ['required', Rule::enum(StakeholderRole::class)],
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
            'email.unique' => __('This email is already a stakeholder of one of your customers.'),
        ];
    }
}
