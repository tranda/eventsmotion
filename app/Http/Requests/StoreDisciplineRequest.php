<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisciplineRequest extends FormRequest
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
            'event_id' => 'required|integer|exists:events,id',
            'distance' => 'required|string|max:50',
            'age_group' => 'required|string|max:50',
            'gender_group' => 'required|string|max:20',
            'boat_group' => 'required|string|max:50',
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
            'event_id.required' => 'Event ID is required.',
            'event_id.exists' => 'The specified event does not exist.',
            'distance.required' => 'Distance is required.',
            'age_group.required' => 'Age group is required.',
            'gender_group.required' => 'Gender group is required.',
            'boat_group.required' => 'Boat group is required.',
            'status.in' => 'Status must be either active or inactive.',
        ];
    }
}