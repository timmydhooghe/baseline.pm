<?php

namespace App\Http\Requests\Dependencies;

use App\Models\Dependency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class UpdateDependencyRequest extends StoreDependencyRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dependency = $this->route('dependency');

        return $dependency instanceof Dependency
            && ($this->user()?->can('update', $dependency) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $dependency = $this->route('dependency');

        return $this->dependencyRules($dependency instanceof Dependency ? $dependency->engagement : null);
    }

    /**
     * A settled item is closed: its required date is what any attributed
     * delay was measured against, so it stops moving once the item arrived
     * or was waived.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $dependency = $this->route('dependency');

                if ($dependency instanceof Dependency && ! $dependency->status->isOutstanding()) {
                    $validator->errors()->add('title', __('This dependency is settled — its record and trail are closed.'));
                }
            },
        ];
    }
}
