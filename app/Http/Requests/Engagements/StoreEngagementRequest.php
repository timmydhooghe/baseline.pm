<?php

namespace App\Http\Requests\Engagements;

use App\Models\Customer;
use App\Models\Engagement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEngagementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Engagement::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => [
                'required',
                'uuid',
                Rule::exists(Customer::class, 'id')->where('organization_id', $this->user()?->organization_id),
            ],
        ];
    }

    /**
     * Enforce the plan's active-engagement limit; archived engagements
     * do not occupy a slot.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $organization = $this->user()?->organization;

                if ($organization === null || ! $organization->hasReachedActiveEngagementLimit()) {
                    return;
                }

                $validator->errors()->add('plan', $organization->activeEngagementLimitMessage());
            },
        ];
    }
}
