<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WidgetAskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('ai.widget.enabled');
    }

    public function rules(): array
    {
        return [
            'question' => [
                'required',
                'string',
                'max:'.(int) config('ai.widget.max_question_chars', 400),
            ],
            'session' => [
                'required',
                'string',
                'max:128',
            ],
            'token' => [
                'required',
                'string',
                'max:1024',
            ],
            'nonce' => [
                'required',
                'string',
                'max:64',
            ],
        ];
    }
}
