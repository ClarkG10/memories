<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('memory')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order' => [
                'required',
                'array',
                'min:1',
                'max:'.config('memories.uploads.max_files_per_memory'),
            ],
            'order.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.required' => 'Nothing to reorder.',
            'order.*.distinct' => 'That order names the same file twice.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function order(): array
    {
        return array_values($this->safe()->array('order'));
    }
}
