<?php

namespace App\Http\Requests\ChangeRequests;

use App\Models\ChangeRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitChangeRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $changeRequest = $this->route('changeRequest');

        return $changeRequest instanceof ChangeRequest
            && ($this->user()?->can('update', $changeRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Submission freezes the proposal and starts the customer clock, so the
     * respond-by deadline is required and lies in the future (FA-13).
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
