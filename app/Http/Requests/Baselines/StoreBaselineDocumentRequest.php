<?php

namespace App\Http\Requests\Baselines;

use App\Models\Baseline;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBaselineDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $baseline = $this->route('baseline');

        return $baseline instanceof Baseline
            && ($this->user()?->can('update', $baseline) ?? false);
    }

    /**
     * Get the validation rules that apply to the request. Contract documents
     * are office-type files up to 25 MB.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'max:25600', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,md'],
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
            'document.max' => __('Contract documents may be at most 25 MB.'),
            'document.mimes' => __('Upload the contract as a PDF, Office document or text file.'),
        ];
    }
}
