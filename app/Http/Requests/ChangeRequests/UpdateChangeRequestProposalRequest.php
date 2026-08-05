<?php

namespace App\Http\Requests\ChangeRequests;

use App\Models\ChangeRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChangeRequestProposalRequest extends FormRequest
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
     * The customer price is the one commercial number a customer sees
     * (FA-12): numeric euros, suggested from cost × target margin but set
     * deliberately. Internal cost stays derived and is never posted.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
        ];
    }
}
