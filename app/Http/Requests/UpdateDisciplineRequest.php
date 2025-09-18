<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDisciplineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user() && $this->user()->access_level >= 3;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'event_id' => 'sometimes|integer|exists:events,id',
            'distance' => 'sometimes|string|max:50',
            'age_group' => 'sometimes|string|max:50',
            'gender_group' => 'sometimes|string|max:20',
            'boat_group' => 'sometimes|string|max:50',
            'status' => 'sometimes|string|in:active,inactive',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'event_id.exists' => 'The specified event does not exist.',
            'status.in' => 'Status must be either active or inactive.',
        ];
    }
}