<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class AskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ];
    }

    public function conversation(): ?Conversation
    {
        $id = $this->validated('conversation_id');

        if ($id === null) {
            return null;
        }

        $conversation = Conversation::query()
            ->where('user_id', $this->user()->id)                                // yours only; the tenant scope already applied
            ->findOrFail($id);

        if (! $conversation->isOpen()) {
            throw ValidationException::withMessages(['conversation_id' => 'That conversation is closed. Start a new one.']);
        }

        return $conversation;
    }
}
