<?php

namespace App\Http\Requests\Organization;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    /**
     * Only the owner changes roles, and never their own — the organization
     * must always keep its owner.
     */
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof User
            && $this->user()?->isNot($member) === true
            && $this->user()->can('update', $member);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(UserRole::class)->except(UserRole::Owner)],
        ];
    }
}
