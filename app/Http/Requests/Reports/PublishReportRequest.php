<?php

namespace App\Http\Requests\Reports;

use App\Models\Engagement;
use App\Models\Report;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publishing the week's report (FA-26). The week is the only input — the
 * report's content is derived from evidence at publish time, never posted,
 * so a client cannot freeze a story the ledgers do not tell.
 */
class PublishReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof Engagement
            && ($this->user()?->can('create', [Report::class, $engagement]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date'],
        ];
    }
}
