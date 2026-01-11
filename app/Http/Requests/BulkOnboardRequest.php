<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkOnboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if(count($this->all()) == 0)
        {
            return [
                '' => 'required'
            ];
        }
        return [
            '*.name' => 'required|string|max:255',
            '*.domain' => 'required|string|max:255',
            '*.contact_email' => 'nullable|email|max:255',
        ];
    }

    public function messages(): array
    {
        if(count($this->all()) == 0)
        {
            return [
                '' => 'No organizations provided'
            ];
        }
        return [
            '*.name.required' => 'Organization name is required',
            '*.domain.required' => 'Organization domain is required',
            '*.contact_email.email' => 'Contact email must be a valid email address',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422)
        );
    }
}
