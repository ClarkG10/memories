<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file_name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],

            /*
             | Recorded for diagnostics only. What the file actually is gets
             | decided from its bytes once it has all arrived.
             */
            'mime_type' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size.required' => "We couldn't tell how big that file is.",
        ];
    }
}
