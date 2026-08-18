<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReorderMediaRequest extends FormRequest
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
