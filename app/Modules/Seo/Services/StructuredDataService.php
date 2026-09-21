<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;

/**
 * Structured data (JSON-LD) generator for events (ТЗ §38).
 *
 * Generates schema.org Event markup with nested Offer, Place, and Organization
 * entities. This enables rich snippets in search results.
 */
class StructuredDataService
{
    /**
     * Mapping of internal event statuses to schema.org EventStatus types.
     */
    private const EVENT_STATUS_MAP = [
        EventStateMachine::PUBLISHED => 'https://schema.org/EventScheduled',
        EventStateMachine::SCHEDULED => 'https://schema.org/EventScheduled',
        EventStateMachine::CANCELLED => 'https://schema.org/EventCancelled',
        EventStateMachine::COMPLETED => 'https://schema.org/EventCompleted',
        EventStateMachine::ARCHIVED => 'https://schema.org/EventCompleted',
    ];

    /**
     * Default event status when status is unknown or draft.
     */
    private const DEFAULT_EVENT_STATUS = 'https://schema.org/EventScheduled';

    /**
     * Attendance mode mappings.
     */
    private const ATTENDANCE_MODE_MAP = [
        'offline' => 'https://schema.org/OfflineEventAttendanceMode',
        'online' => 'https://schema.org/OnlineEventAttendanceMode',
        'mixed' => 'https://schema.org/MixedEventAttendanceMode',
    ];

    /**
     * Default attendance mode.
     */
    private const DEFAULT_ATTENDANCE_MODE = 'https://schema.org/OfflineEventAttendanceMode';

    /**
     * Generate JSON-LD structured data for an event page.
     *
     * @param array<string, mixed> $eventData Event data including relations
     *
     * @return array<string, mixed> JSON-LD structure
     */
    public function generateEventData(array $eventData): array
    {
        $baseUrl = config('app.url');

        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $eventData['title'] ?? '',
            'description' => $eventData['short_description'] ?? $eventData['description'] ?? '',
            'image' => $this->buildImageUrls($eventData),
            'startDate' => $this->formatDateTime($eventData['start_datetime'] ?? null),
            'endDate' => $this->formatDateTime($eventData['end_datetime'] ?? null),
            'eventStatus' => $this->getEventStatus($eventData['status'] ?? EventStateMachine::DRAFT),
            'eventAttendanceMode' => $this->getAttendanceMode($eventData['attendance_mode'] ?? 'offline'),
            'url' => $this->getCanonicalUrl($eventData),
            'location' => [
                '@type' => 'Place',
                'name' => $eventData['venue']['name'] ?? '',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $eventData['venue']['address'] ?? '',
                    'addressLocality' => $eventData['venue']['city'] ?? '',
                    'postalCode' => $eventData['venue']['postal_code'] ?? '',
                    'addressCountry' => $eventData['venue']['country'] ?? 'RU',
                ],
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => $eventData['organization']['name'] ?? '',
                'url' => $baseUrl,
            ],
            'offers' => $this->buildOffers($eventData),
        ];

        // Add performer if applicable
        if (! empty($eventData['performer'])) {
            $structuredData['performer'] = [
                '@type' => 'PerformingGroup',
                'name' => $eventData['performer']['name'] ?? '',
            ];
        }

        return $structuredData;
    }

    /**
     * Get canonical URL for an event.
     *
     * @param array<string, mixed> $eventData
     */
    public function getCanonicalUrl(array $eventData): string
    {
        // Use explicit canonical_url if set, otherwise generate from slug/public_id
        if (!empty($eventData['canonical_url'])) {
            return $eventData['canonical_url'];
        }

        return route('events.show', [
            'slug' => $eventData['slug'] ?? '',
            'publicId' => $eventData['public_id'] ?? '',
        ]);
    }

    /**
     * Map internal status to schema.org EventStatus.
     */
    private function getEventStatus(string $status): string
    {
        return self::EVENT_STATUS_MAP[$status] ?? self::DEFAULT_EVENT_STATUS;
    }

    /**
     * Get attendance mode from internal representation.
     */
    private function getAttendanceMode(string $mode): string
    {
        return self::ATTENDANCE_MODE_MAP[$mode] ?? self::DEFAULT_ATTENDANCE_MODE;
    }

    /**
     * Build image URLs array for structured data.
     *
     * @param array<string, mixed> $eventData Event data
     *
     * @return array<int, string> Image URLs
     */
    private function buildImageUrls(array $eventData): array
    {
        $images = [];

        if (! empty($eventData['poster'])) {
            $images[] = $this->resolveImageUrl($eventData['poster']);
        }

        if (! empty($eventData['cover'])) {
            $images[] = $this->resolveImageUrl($eventData['cover']);
        }

        return $images !== [] ? $images : [config('app.url') . '/images/og-default.jpg'];
    }

    /**
     * Resolve image URL from storage path.
     */
    private function resolveImageUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return config('app.url') . '/storage/' . ltrim($path, '/');
    }

    /**
     * Build offers array for structured data.
     *
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    private function buildOffers(array $eventData): array
    {
        // Handle multiple tickets if provided
        if (! empty($eventData['tickets']) && is_array($eventData['tickets'])) {
            $offers = [];
            foreach ($eventData['tickets'] as $ticket) {
                $offers[] = [
                    '@type' => 'Offer',
                    'name' => $ticket['name'] ?? 'General Admission',
                    'price' => $ticket['price'] ?? 0,
                    'priceCurrency' => $ticket['currency'] ?? 'RUB',
                    'availability' => $this->getOfferAvailability($ticket['status'] ?? 'available'),
                    'validFrom' => $this->formatDateTime($ticket['sale_start'] ?? null),
                    'validThrough' => $this->formatDateTime($ticket['sale_end'] ?? $eventData['start_datetime'] ?? null),
                    'url' => route('checkout.select', ['eventId' => $eventData['public_id'] ?? '']),
                ];
            }
            return $offers;
        }

        // Single default offer
        return [
            '@type' => 'Offer',
            'name' => 'General Admission',
            'url' => route('events.show', ['eventId' => $eventData['public_id'] ?? '']),
            'price' => $eventData['ticket_price'] ?? 0,
            'priceCurrency' => $eventData['currency'] ?? 'RUB',
            'availability' => $this->getOfferAvailability($eventData['status'] ?? EventStateMachine::DRAFT),
            'validFrom' => $this->formatDateTime($eventData['published_at'] ?? null),
        ];
    }

    /**
     * Map event/ticket status to schema.org ItemAvailability.
     */
    private function getOfferAvailability(string $status): string
    {
        return match ($status) {
            'sold_out', EventStateMachine::COMPLETED, EventStateMachine::ARCHIVED => 'https://schema.org/SoldOut',
            'limited' => 'https://schema.org/LimitedAvailability',
            'unavailable', EventStateMachine::CANCELLED => 'https://schema.org/OutOfStock',
            default => 'https://schema.org/InStock',
        };
    }

    /**
     * Format datetime for schema.org.
     */
    private function formatDateTime(?string $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($datetime)->toIso8601String();
        } catch (\Exception) {
            return null;
        }
    }
}
