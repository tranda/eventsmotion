<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'location' => 'sometimes|string|max:255',
            'year' => 'sometimes|integer|min:2020|max:2050',
            'status' => 'sometimes|string|in:active,inactive',
            'standard_reserves' => 'sometimes|integer|min:0',
            'standard_min_gender' => 'sometimes|integer|min:0',
            'standard_max_gender' => 'sometimes|integer|min:0',
            'small_reserves' => 'sometimes|integer|min:0',
            'small_min_gender' => 'sometimes|integer|min:0',
            'small_max_gender' => 'sometimes|integer|min:0',
            'race_entries_lock' => 'sometimes|date',
            'name_entries_lock' => 'sometimes|date',
            'crew_entries_lock' => 'sometimes|date',
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
            'year.min' => 'Event year must be 2020 or later.',
            'year.max' => 'Event year must be 2050 or earlier.',
            'status.in' => 'Status must be either active or inactive.',
        ];
    }
}