<?php

namespace App\Http\Requests\ChangeRequests;

use App\Enums\ChangeRequestOrigin;
use App\Models\ChangeRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChangeRequestRequest extends FormRequest
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
     * The narrative fields of a change request (FA-12). A scope creep origin is
     * evidence from triage and cannot be claimed or changed by hand.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $changeRequest = $this->route('changeRequest');
        $changeRequest = $changeRequest instanceof ChangeRequest ? $changeRequest : null;

        $originRule = $changeRequest?->origin === ChangeRequestOrigin::ScopeCreep
            ? Rule::enum(ChangeRequestOrigin::class)->only(ChangeRequestOrigin::ScopeCreep)
            : Rule::enum(ChangeRequestOrigin::class)->except(ChangeRequestOrigin::ScopeCreep);

        return [
            'title' => ['required', 'string', 'max:255'],
            'what' => ['required', 'string', 'max:5000'],
            'why' => ['nullable', 'string', 'max:5000'],
            'origin' => ['required', $originRule],
        ];
    }
}
