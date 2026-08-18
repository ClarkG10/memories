<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Memory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Memory::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'memory_date' => ['required', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:tomorrow'],
            'location' => ['nullable', 'string', 'max:160'],
            'album' => ['nullable', 'string', 'max:80'],

            'uploads' => [
                'required',
                'array',
                'min:1',
                'max:'.config('memories.uploads.max_files_per_memory'),
            ],
            'uploads.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Give this memory a title.',
            'memory_date.required' => 'Choose the day this happened.',
            'memory_date.before_or_equal' => "That date hasn't happened yet.",
            'uploads.required' => 'Add at least one photo or video.',
            'uploads.max' => 'That is more files than one memory can hold.',
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

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }

        /*
         | The album name becomes a Drive folder name, so anything that would
         | change the meaning of a path — slashes, control characters, leading
         | dots — is stripped rather than rejected. Someone naming an album
         | should not have to think about filesystems.
         */
        if (is_string($this->input('album'))) {
            $album = preg_replace('/[^\\p{L}\\p{N} &._-]+/u', '', $this->input('album')) ?? '';
            $album = trim($album, " \t.-");

            $this->merge(['album' => $album === '' ? null : $album]);
        }
    }

    /**
     * The client's guarantee that this is one intent, not a replay of a memory
     * that was already saved.
     */
    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function memoryAttributes(): array
    {
        return $this->safe()->only(['title', 'description', 'memory_date', 'location', 'album']);
    }

    /**
     * @return array<int, string>
     */
    public function uploadUuids(): array
    {
        return array_values($this->safe()->array('uploads'));
    }

    /**
     * Only the fields that identify the intent take part in the idempotency
     * hash, so retrying the same save is recognised as the same request.
     *
     * @return array<string, mixed>
     */
    public function idempotencyPayload(): array
    {
        return [
            'title' => $this->input('title'),
            'memory_date' => $this->input('memory_date'),
            'uploads' => $this->uploadUuids(),
        ];
    }
}
