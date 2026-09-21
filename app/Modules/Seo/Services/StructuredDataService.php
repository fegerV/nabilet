<?php

declare(strict_types=1);

namespace App\Modules\Seo\Services;

use App\Modules\Events\Models\Event;

/**
 * Structured data (JSON-LD) generator for events (ТЗ §38).
 *
 * Generates schema.org Event markup with nested Offer, Place, and Organization
 * entities. This enables rich snippets in search results.
 */
class StructuredDataService
{
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
            'eventStatus' => $this->mapEventStatus($eventData['status'] ?? 'draft'),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url' => $eventData['canonical_url'] ?? route('events.show', [
                'slug' => $eventData['slug'] ?? '',
                'publicId' => $eventData['public_id'] ?? '',
            ]),
        ];

        // Add organizer
        if (! empty($eventData['organization'])) {
            $structuredData['organizer'] = [
                '@type' => 'Organization',
                'name' => $eventData['organization']['name'] ?? '',
                'url' => $baseUrl,
            ];
        }

        // Add venue/place
        if (! empty($eventData['venue'])) {
            $structuredData['location'] = [
                '@type' => 'Place',
                'name' => $eventData['venue']['name'] ?? '',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $eventData['venue']['address'] ?? '',
                    'addressLocality' => $eventData['venue']['city'] ?? '',
                    'postalCode' => $eventData['venue']['postal_code'] ?? '',
                    'addressCountry' => $eventData['venue']['country'] ?? 'RU',
                ],
            ];
        }

        // Add offers (tickets)
        if (! empty($eventData['tickets']) && is_array($eventData['tickets'])) {
            $offers = [];
            foreach ($eventData['tickets'] as $ticket) {
                $offers[] = [
                    '@type' => 'Offer',
                    'name' => $ticket['name'] ?? 'General Admission',
                    'price' => $ticket['price'] ?? 0,
                    'priceCurrency' => $ticket['currency'] ?? 'RUB',
                    'availability' => $this->mapOfferAvailability($ticket['status'] ?? 'available'),
                    'validFrom' => $this->formatDateTime($ticket['sale_start'] ?? null),
                    'validThrough' => $this->formatDateTime($ticket['sale_end'] ?? $eventData['start_datetime'] ?? null),
                    'url' => route('checkout.select', ['eventId' => $eventData['public_id'] ?? '']),
                ];
            }
            $structuredData['offers'] = $offers;
        }

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
     * Generate breadcrumb JSON-LD.
     *
     * @param array<int, array{name: string, url: string}> $items Breadcrumb items
     *
     * @return array<string, mixed> JSON-LD structure
     */
    public function generateBreadcrumbData(array $items): array
    {
        $itemListElements = [];

        foreach ($items as $index => $item) {
            $itemListElements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $itemListElements,
        ];
    }

    /**
     * Generate organization JSON-LD.
     *
     * @param array<string, mixed> $organizationData Organization details
     *
     * @return array<string, mixed> JSON-LD structure
     */
    public function generateOrganizationData(array $organizationData): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $organizationData['name'] ?? '',
            'url' => $organizationData['url'] ?? config('app.url'),
            'logo' => $organizationData['logo'] ?? null,
            'sameAs' => $organizationData['social_links'] ?? [],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => $organizationData['phone'] ?? null,
                'email' => $organizationData['email'] ?? null,
                'contactType' => 'customer service',
            ],
        ];
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
     * Format datetime for schema.org.
     */
    private function formatDateTime(?\string $datetime): ?string
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

    /**
     * Map internal status to schema.org EventStatus.
     */
    private function mapEventStatus(string $status): string
    {
        return match ($status) {
            'cancelled' => 'https://schema.org/EventCancelled',
            'postponed' => 'https://schema.org/EventPostponed',
            'rescheduled' => 'https://schema.org/EventRescheduled',
            'completed' => 'https://schema.org/EventCompleted',
            default => 'https://schema.org/EventScheduled',
        };
    }

    /**
     * Map ticket status to schema.org ItemAvailability.
     */
    private function mapOfferAvailability(string $status): string
    {
        return match ($status) {
            'sold_out' => 'https://schema.org/SoldOut',
            'limited' => 'https://schema.org/LimitedAvailability',
            'unavailable' => 'https://schema.org/OutOfStock',
            default => 'https://schema.org/InStock',
        };
    }
}
