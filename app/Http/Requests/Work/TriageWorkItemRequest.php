<?php

namespace App\Http\Requests\Work;

use App\Enums\WorkItemTriageStatus;
use App\Models\WorkItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TriageWorkItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workItem = $this->route('workItem');

        return $workItem instanceof WorkItem
            && ($this->user()?->can('triage', $workItem) ?? false);
    }

    /**
     * Get the validation rules that apply to the request. That the item is
     * actually drift and the deliverable belongs to this engagement is
     * enforced by WorkItem::triage().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'classification' => ['required', Rule::enum(WorkItemTriageStatus::class)],
            'baseline_item_id' => ['nullable', 'required_if:classification,existing_scope', 'uuid'],
            'note' => ['nullable', 'required_if:classification,operational', 'string', 'max:2000'],
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
            'baseline_item_id.required_if' => __('Existing scope requires the deliverable that absorbs the work.'),
            'note.required_if' => __('Excluding work as operational requires an explanation — it stays on the record.'),
        ];
    }
}
