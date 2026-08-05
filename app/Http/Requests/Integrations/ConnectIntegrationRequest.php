<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * Get the validation rules that apply to the request. Jira needs the
     * customer's site URL and an email + API token pair; Linear only an API
     * key. The project key is the Jira project or Linear team to sync.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(IntegrationProvider::class)],
            'external_project_key' => ['required', 'string', 'max:50'],
            'base_url' => ['required_if:provider,jira', 'nullable', 'url:https', 'max:255'],
            'email' => ['required_if:provider,jira', 'nullable', 'email', 'max:255'],
            'api_token' => ['required', 'string', 'max:2048'],
        ];
    }

    /**
     * The server requests the Jira URL itself, so anything but the
     * customer's own Atlassian cloud origin would be a server-side request
     * forgery vector (loopback, cloud metadata, internal services).
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('provider') !== IntegrationProvider::Jira->value || $validator->errors()->has('base_url')) {
                    return;
                }

                $url = (string) $this->input('base_url');
                $host = parse_url($url, PHP_URL_HOST);
                $port = parse_url($url, PHP_URL_PORT);

                if (! is_string($host) || $port !== null || ! str_ends_with(strtolower($host), '.atlassian.net')) {
                    $validator->errors()->add('base_url', __('Jira Cloud connections must point at your own https://<site>.atlassian.net URL.'));
                }
            },
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
