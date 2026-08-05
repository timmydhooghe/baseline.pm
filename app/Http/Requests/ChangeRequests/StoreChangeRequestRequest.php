<?php

namespace App\Http\Requests\ChangeRequests;

use App\Enums\ChangeRequestOrigin;
use App\Models\ChangeRequest;
use App\Models\Engagement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChangeRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [ChangeRequest::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Drift-born drafts come from triage (FA-9); requests raised by hand
     * carry any other origin.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'what' => ['required', 'string', 'max:5000'],
            'why' => ['nullable', 'string', 'max:5000'],
            'origin' => ['required', Rule::enum(ChangeRequestOrigin::class)->except(ChangeRequestOrigin::Drift)],
            'estimated_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000'],
        ];
    }
}
