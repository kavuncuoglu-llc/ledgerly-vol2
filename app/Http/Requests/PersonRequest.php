<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PersonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
