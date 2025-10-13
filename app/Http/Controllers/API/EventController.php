<?php

namespace App\Http\Controllers\API;
use App\Http\Controllers\API\BaseController as BaseController;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;

use Illuminate\Http\Request;
use App\Models\Event;

class EventController extends BaseController
{
    /**
     * Get all events (enhanced with pagination)
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {
            $query = Event::query();

            // Add filtering by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Add filtering by year if provided
            if ($request->has('year')) {
                $query->where('year', $request->year);
            }

            // Add pagination
            if ($request->has('per_page')) {
                $events = $query->paginate($request->per_page);
            } else {
                $events = $query->get();
            }

            return $this->sendResponse($events, 'Events retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving events.', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all events (backward compatibility)
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getAllEvents(Request $request)
    {
        $query = Event::query();

        // Filter by available flag unless 'all' parameter is true
        if (!$request->has('all') || $request->all !== 'true') {
            $query->where('available', 1);
        }

        $events = $query->get();
        return response()->json($events);
    }

    /**
     * Get a single event by ID
     * 
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            $event = Event::with('disciplines')->findOrFail($id);
            return $this->sendResponse($event, 'Event retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Event not found.', [], 404);
        }
    }

    /**
     * Create a new event
     * 
     * @param StoreEventRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreEventRequest $request)
    {
        try {
            $event = Event::create([
                'name' => $request->name,
                'location' => $request->location,
                'year' => $request->year,
                'status' => $request->status ?? 'active',
                'available' => $request->available ?? true,
                'standard_reserves' => $request->standard_reserves,
                'standard_min_gender' => $request->standard_min_gender,
                'standard_max_gender' => $request->standard_max_gender,
                'small_reserves' => $request->small_reserves,
                'small_min_gender' => $request->small_min_gender,
                'small_max_gender' => $request->small_max_gender,
                'race_entries_lock' => $request->race_entries_lock,
                'name_entries_lock' => $request->name_entries_lock,
                'crew_entries_lock' => $request->crew_entries_lock,
            ]);

            return $this->sendResponse($event, 'Event created successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error creating event.', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing event
     * 
     * @param UpdateEventRequest $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateEventRequest $request, $id)
    {
        try {
            $event = Event::findOrFail($id);
            
            $updateData = [];
            if ($request->has('name')) $updateData['name'] = $request->name;
            if ($request->has('location')) $updateData['location'] = $request->location;
            if ($request->has('year')) $updateData['year'] = $request->year;
            if ($request->has('status')) $updateData['status'] = $request->status;
            if ($request->has('available')) $updateData['available'] = $request->available;
            if ($request->has('standard_reserves')) $updateData['standard_reserves'] = $request->standard_reserves;
            if ($request->has('standard_min_gender')) $updateData['standard_min_gender'] = $request->standard_min_gender;
            if ($request->has('standard_max_gender')) $updateData['standard_max_gender'] = $request->standard_max_gender;
            if ($request->has('small_reserves')) $updateData['small_reserves'] = $request->small_reserves;
            if ($request->has('small_min_gender')) $updateData['small_min_gender'] = $request->small_min_gender;
            if ($request->has('small_max_gender')) $updateData['small_max_gender'] = $request->small_max_gender;
            if ($request->has('race_entries_lock')) $updateData['race_entries_lock'] = $request->race_entries_lock;
            if ($request->has('name_entries_lock')) $updateData['name_entries_lock'] = $request->name_entries_lock;
            if ($request->has('crew_entries_lock')) $updateData['crew_entries_lock'] = $request->crew_entries_lock;

            $event->update($updateData);

            return $this->sendResponse($event, 'Event updated successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error updating event.', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete an event (soft delete)
     * 
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $event = Event::findOrFail($id);
            
            // Check if event has disciplines - prevent deletion if disciplines exist
            $disciplineCount = $event->disciplines()->count();
            if ($disciplineCount > 0) {
                return $this->sendError(
                    'Cannot delete event.',
                    ['error' => 'Event has associated disciplines and cannot be deleted.'],
                    409
                );
            }

            $event->delete(); // This will be a soft delete due to SoftDeletes trait

            return $this->sendResponse([], 'Event deleted successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error deleting event.', ['error' => $e->getMessage()], 500);
        }
    }
}
