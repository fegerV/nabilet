# Event Calendar System Documentation

## Overview

This document describes the event calendar system implemented for the NABILET platform. The system allows events to have multiple dates/sessions, enabling support for:

- Multi-day festivals
- Recurring shows (e.g., daily performances)
- Different time slots for the same event
- Capacity management per date

## Database Schema

### event_dates Table

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Primary key |
| public_id | CHAR(26) | Unique public identifier (ULID) |
| event_id | BIGINT | Foreign key to events table |
| start_at | DATETIME(6) | When the event date starts |
| end_at | DATETIME(6) | When the event date ends (optional) |
| status | VARCHAR(32) | scheduled, completed, cancelled, postponed |
| name | VARCHAR(255) | Optional name (e.g., "Day 1", "Evening Show") |
| capacity | INT | Optional capacity override for this date |
| is_sold_out | BOOLEAN | Whether tickets are sold out |
| sales_start_at | DATETIME(6) | When ticket sales begin (optional) |
| sales_end_at | DATETIME(6) | When ticket sales end (optional) |
| notes | TEXT | Internal notes about this date |
| created_at | DATETIME(6) | Record creation timestamp |
| updated_at | DATETIME(6) | Record update timestamp |
| deleted_at | DATETIME(6) | Soft delete timestamp |

## Models

### EventDate Model

Located at: `app/Models/EventDate.php`

Key features:
- Soft deletes support
- Relationship to Event model
- `isAvailable()` method to check booking availability
- Automatic public_id generation using ULID

### Event Model (Updated)

Located at: `app/Models/Event.php`

New relationships and methods:
- `dates()` - HasMany relationship to EventDate
- `upcomingDates()` - Scope for future scheduled dates
- `getNextDateAttribute()` - Accessor for earliest upcoming date
- `hasAvailableDates()` - Check if event has available dates

## Admin Panel (Filament)

### EventDateResource

Location: `app/Filament/Resources/EventDateResource.php`

Features:
- Full CRUD operations for event dates
- Filter by status, event, and sold-out status
- Form fields for all date properties
- Color-coded status badges

### EventCalendarWidget

Location: `app/Filament/Widgets/EventCalendarWidget.php`

Displays:
- Upcoming event dates (next 10)
- Current month's events grouped by date
- Availability status (Sold Out / Available)
- Status indicators with color coding

View template: `resources/views/filament/widgets/event-calendar-widget.blade.php`

## Usage Examples

### Creating an Event with Multiple Dates

```php
use App\Models\Event;
use App\Models\EventDate;

// Create the event
$event = Event::create([
    'title' => 'Summer Music Festival',
    'slug' => 'summer-music-festival-2024',
    'status' => 'published',
    // ... other fields
]);

// Add multiple dates
EventDate::create([
    'event_id' => $event->id,
    'name' => 'Day 1',
    'start_at' => '2024-07-15 18:00:00',
    'end_at' => '2024-07-15 23:00:00',
    'capacity' => 5000,
    'status' => 'scheduled',
]);

EventDate::create([
    'event_id' => $event->id,
    'name' => 'Day 2',
    'start_at' => '2024-07-16 18:00:00',
    'end_at' => '2024-07-16 23:00:00',
    'capacity' => 5000,
    'status' => 'scheduled',
]);
```

### Checking Availability

```php
// Check if a specific date is available
if ($eventDate->isAvailable()) {
    // Proceed with booking
}

// Check if event has any available dates
if ($event->hasAvailableDates()) {
    // Show booking options
}

// Get next upcoming date
$nextDate = $event->nextDate;
```

### Querying Event Dates

```php
// Get all upcoming dates for an event
$upcomingDates = $event->upcomingDates()->get();

// Get dates within a specific range
$dates = EventDate::whereBetween('start_at', [$startDate, $endDate])
    ->where('status', 'scheduled')
    ->where('is_sold_out', false)
    ->get();

// Get sold out dates
$soldOutDates = $event->dates()
    ->where('is_sold_out', true)
    ->get();
```

## API Considerations

When building the API, consider these endpoints:

- `GET /api/events/{event}/dates` - List all dates for an event
- `GET /api/events/{event}/dates/upcoming` - List only upcoming dates
- `POST /api/events/{event}/dates` - Create a new date (admin only)
- `PUT /api/event-dates/{date}` - Update a date (admin only)
- `DELETE /api/event-dates/{date}` - Delete a date (admin only)

## Migration

Run the migration to create the event_dates table:

```bash
php artisan migrate
```

The migration file is located at:
`database/migrations/2026_09_22_001400_create_event_dates_table.php`

## Future Enhancements

Potential improvements for future iterations:

1. **Recurring Events**: Add support for recurring date patterns (daily, weekly, monthly)
2. **Timezone Support**: Store and display dates in user's timezone
3. **iCal Export**: Allow users to add event dates to their calendars
4. **Waitlist**: Implement waitlist functionality for sold-out dates
5. **Dynamic Pricing**: Different pricing for different dates
6. **Seat Mapping**: Integration with hall schemas for reserved seating per date
