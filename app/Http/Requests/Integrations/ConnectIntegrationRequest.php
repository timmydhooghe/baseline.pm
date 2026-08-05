<?php

namespace App\Http\Requests\Integrations;

use App\Models\Engagement;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectIntegrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [IntegrationConnection::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request. Credentials live
     * on the org-level account being picked; the project key is the Jira
     * project or Linear team to sync. Rule::exists ignores global scopes,
     * so the organization clause is the tenant boundary here — a foreign
     * account id must read as nonexistent.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'integration_account_id' => [
                'required',
                'uuid',
                Rule::exists('integration_accounts', 'id')
                    ->where('organization_id', (string) $this->user()?->organization_id),
            ],
            'external_project_key' => ['required', 'string', 'max:50'],
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
            'integration_account_id.required' => __('Pick one of your organization\'s provider accounts.'),
            'integration_account_id.exists' => __('Pick one of your organization\'s provider accounts.'),
        ];
    }
}
