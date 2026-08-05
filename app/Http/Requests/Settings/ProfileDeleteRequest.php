<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * The organization owner can never delete their account — every
     * organization must keep its owner, and ownership cannot be transferred.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->user()?->role === UserRole::Owner) {
                    $validator->errors()->add('account', __('As the organization owner you cannot delete your account.'));
                }
            },
        ];
    }
}
