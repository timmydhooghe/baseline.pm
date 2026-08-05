<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The server requests the Jira URL itself, so anything but the customer's
 * own Atlassian cloud origin would be a server-side request forgery vector
 * (loopback, cloud metadata, internal services).
 */
class AtlassianCloudUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = (string) $value;
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || $port !== null || ! str_ends_with(strtolower($host), '.atlassian.net')) {
            $fail(__('Jira Cloud connections must point at your own https://<site>.atlassian.net URL.'));
        }
    }
}
