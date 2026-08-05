<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationAccount;
use App\Rules\AtlassianCloudUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntegrationAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', IntegrationAccount::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request. Jira needs the
     * customer's site URL and an email + API token pair; Linear only an API
     * key. The name labels the account wherever engagements pick one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(IntegrationProvider::class)],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('integration_accounts', 'name')
                    ->where('organization_id', (string) $this->user()?->organization_id),
            ],
            'base_url' => ['required_if:provider,jira', 'nullable', 'url:https', 'max:255', new AtlassianCloudUrl],
            'email' => ['required_if:provider,jira', 'nullable', 'email', 'max:255'],
            'api_token' => ['required', 'string', 'max:2048'],
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
            'base_url.required_if' => __('Jira needs your site URL (https://your-team.atlassian.net).'),
            'email.required_if' => __('Jira authenticates with the account email that owns the API token.'),
        ];
    }

    /**
     * The credential set to store, shaped per provider.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        $validated = $this->validated();

        return IntegrationProvider::from($validated['provider']) === IntegrationProvider::Jira
            ? ['email' => $validated['email'], 'api_token' => $validated['api_token']]
            : ['api_token' => $validated['api_token']];
    }
}
