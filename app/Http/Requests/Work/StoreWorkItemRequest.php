<?php

namespace App\Http\Requests\Work;

use App\Enums\WorkItemState;
use App\Models\Engagement;
use App\Models\WorkItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [WorkItem::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request. Manual items
     * estimate in days — the standalone counterpart of a provider estimate.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'state' => ['required', Rule::enum(WorkItemState::class)],
            'type' => ['nullable', 'string', 'max:50'],
            'assignee_name' => ['nullable', 'string', 'max:255'],
            'estimate_days' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ];
    }
}
