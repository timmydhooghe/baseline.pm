<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationAccount;
use App\Rules\AtlassianCloudUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntegrationAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof IntegrationAccount
            && ($this->user()?->can('update', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request. The provider is
     * immutable; the credentials only change when a fresh API token is
     * submitted (rotation) — Jira rotation needs the owning email with it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $isJira = $account instanceof IntegrationAccount
            && $account->provider === IntegrationProvider::Jira;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('integration_accounts', 'name')
                    ->where('organization_id', (string) $this->user()?->organization_id)
                    ->ignore($account instanceof IntegrationAccount ? $account->id : null),
            ],
            'base_url' => $isJira
                ? ['required', 'url:https', 'max:255', new AtlassianCloudUrl]
                : ['prohibited'],
            'email' => $isJira
                ? ['required_with:api_token', 'nullable', 'email', 'max:255']
                : ['prohibited'],
            'api_token' => ['nullable', 'string', 'max:2048'],
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
            'name.unique' => __('Your organization already has an account with this name.'),
            'email.required_with' => __('Rotating a Jira token needs the account email that owns it.'),
        ];
    }

    /**
     * The fresh credential set when a rotation was submitted, null otherwise.
     *
     * @return array<string, string>|null
     */
    public function credentials(): ?array
    {
        $validated = $this->validated();

        if (($validated['api_token'] ?? null) === null) {
            return null;
        }

        $account = $this->route('account');

        return $account instanceof IntegrationAccount && $account->provider === IntegrationProvider::Jira
            ? ['email' => $validated['email'], 'api_token' => $validated['api_token']]
            : ['api_token' => $validated['api_token']];
    }
}
