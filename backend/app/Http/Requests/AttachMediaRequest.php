<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class AttachMediaRequest extends FormRequest
{
    /**
     * Returns the policy's own answer rather than a boolean, so a refusal
     * arrives with the reason attached instead of "This action is
     * unauthorized" and nothing else.
     */
    public function authorize(): Response|bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->inspect('update', $this->route('memory'));
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
