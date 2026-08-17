<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttachMediaRequest extends FormRequest
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
            'uploads' => [
                'required',
                'array',
                'min:1',
                'max:'.config('memories.uploads.max_files_per_memory'),
            ],
            'uploads.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->idempotencyKey() === '') {
                $validator->errors()->add(
                    'idempotency_key',
                    'This request is missing its Idempotency-Key header.',
                );
            }
        });
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }

    /**
     * @return array<int, string>
     */
    public function uploadUuids(): array
    {
        return array_values($this->safe()->array('uploads'));
    }

    /**
     * @return array<string, mixed>
     */
    public function idempotencyPayload(): array
    {
        return ['uploads' => $this->uploadUuids()];
    }
}
