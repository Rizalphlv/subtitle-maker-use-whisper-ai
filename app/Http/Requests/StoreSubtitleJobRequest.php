<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubtitleJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('subtitle_api.max_upload_kb'), 'extensions:'.implode(',', config('subtitle_api.allowed_extensions')), 'mimetypes:'.implode(',', config('subtitle_api.allowed_mimes'))],
            'language' => ['sometimes', 'string', 'max:20', 'regex:/^(auto|[a-z]{2,3}(?:-[A-Z]{2})?)$/'],
            'output_format' => ['sometimes', Rule::in(['srt'])],
        ];
    }
}
