<?php

namespace App\Http\Requests;

use App\Models\AnswerFeedback;
use Illuminate\Foundation\Http\FormRequest;

class FeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('value') && ! $this->has('rating')) {
            $this->merge(['rating' => $this->input('value')]);          // Chapter 18's pages sent {value}; Chapter 30's send {rating, reason}
        }
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'string', 'in:up,down'],
            'reason' => ['nullable', 'string', 'in:'.implode(',', AnswerFeedback::REASONS), 'prohibited_if:rating,up'],
        ];
    }
}
