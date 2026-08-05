<?php

namespace App\Http\Requests\Deliverables;

use App\Enums\EvidenceKind;
use App\Enums\RecordVisibility;
use App\Models\Deliverable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDeliverableEvidenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $deliverable = $this->route('deliverable');

        return $deliverable instanceof Deliverable
            && ($this->user()?->can('update', $deliverable) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Evidence is typed (FA-22): what it is, what to call it, where it
     * lives, and whether the customer may see it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(EvidenceKind::class)],
            'label' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:2048'],
            'visibility' => ['required', Rule::enum(RecordVisibility::class)],
        ];
    }

    /**
     * A frozen or signed record's evidence list stops moving.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $deliverable = $this->route('deliverable');

                if ($deliverable instanceof Deliverable && ! $deliverable->status->acceptsUpdates()) {
                    $validator->errors()->add('label', __('This record is frozen — it awaits the customer decision or carries a signed acceptance.'));
                }
            },
        ];
    }
}
