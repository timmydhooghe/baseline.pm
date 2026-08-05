<?php

namespace App\Http\Requests\Work;

use App\Enums\WorkItemState;
use App\Models\WorkItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request. The policy
     * refuses synced items — they mirror the provider.
     */
    public function authorize(): bool
    {
        $workItem = $this->route('workItem');

        return $workItem instanceof WorkItem
            && ($this->user()?->can('update', $workItem) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'state' => ['sometimes', 'required', Rule::enum(WorkItemState::class)],
            'type' => ['nullable', 'string', 'max:50'],
            'assignee_name' => ['nullable', 'string', 'max:255'],
            'estimate_days' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ];
    }
}
