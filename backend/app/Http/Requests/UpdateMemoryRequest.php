<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateMemoryRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:'.config('memories.text.title')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('memories.text.description')],
            'memory_date' => ['sometimes', 'required', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:tomorrow'],
            'location' => ['sometimes', 'nullable', 'string', 'max:'.config('memories.text.location')],
            'album' => ['sometimes', 'nullable', 'string', 'max:'.config('memories.text.album')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'A memory needs a title.',
            'memory_date.before_or_equal' => "That date hasn't happened yet.",
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }
}
