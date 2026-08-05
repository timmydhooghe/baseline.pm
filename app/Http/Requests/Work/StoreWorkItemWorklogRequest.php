<?php

namespace App\Http\Requests\Work;

use App\Models\WorkItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkItemWorklogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workItem = $this->route('workItem');

        return $workItem instanceof WorkItem
            && ($this->user()?->can('recordWorklog', $workItem) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'logged_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
