<?php

namespace App\Http\Requests\Deliverables;

use App\Models\Deliverable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitDeliverableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $deliverable = $this->route('deliverable');

        return $deliverable instanceof Deliverable
            && ($this->user()?->can('submit', $deliverable) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Submission freezes the record and starts the customer clock, so the
     * respond-by deadline is required and lies in the future (FA-23).
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
