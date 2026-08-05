<?php

namespace App\Http\Requests\Engagements;

use App\Models\Engagement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitFinalAcceptanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Final acceptance drives a lifecycle transition, so it follows the
     * transition gate: managing roles only.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('transition', $engagement) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Submission freezes the accepted record and starts the customer clock,
     * so the respond-by deadline is required and lies in the future (FA-24).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'respond_by' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
