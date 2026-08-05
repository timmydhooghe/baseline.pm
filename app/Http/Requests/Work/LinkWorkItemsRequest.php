<?php

namespace App\Http\Requests\Work;

use App\Models\Engagement;
use App\Models\WorkItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinkWorkItemsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('linkAny', [WorkItem::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request. That the target
     * is a deliverable on this engagement is enforced by WorkItem::linkTo().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'work_item_ids' => ['required', 'array', 'min:1'],
            'work_item_ids.*' => ['uuid'],
            'baseline_item_id' => ['required', 'uuid'],
        ];
    }
}
