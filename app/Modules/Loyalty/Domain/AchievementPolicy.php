<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Domain;

/** Pure read model: one qualifying admission per session, no financial side effects. */
final class AchievementPolicy
{
    public static function evaluate(array $visits, array $catalog): array
    {
        usort($visits, fn (array $a, array $b): int => strcmp($a['used_at'], $b['used_at']));
        $seen = $categories = $genres = $theatreGenres = $venues = $artists = $months = [];
        $metrics = array_fill_keys(array_column($catalog, 'metric'), 0);
        $earned = [];
        foreach ($visits as $visit) {
            if (isset($seen[$visit['session_id']])) {
                continue;
            }
            $seen[$visit['session_id']] = true;
            $metrics['visits']++;
            $category = self::normalize($visit['category'] ?? '');
            if ($category !== '') {
                $categories[$category] = true;
            }
            $musicGenres = [];
            foreach (array_merge([$category], $visit['genres'] ?? []) as $genre) {
                $normalized = self::musicGenre($genre);
                if ($normalized !== null) {
                    $musicGenres[$normalized] = true;
                }
            }
            $isConcert = in_array($category, ['concert', 'concerts', 'koncert', 'koncerty', 'концерт', 'концерты', 'music', 'музыка'], true) || count($musicGenres) > 0;
            $theatreGenre = match ($category) {
                'comedy', 'komediya', 'комедия' => 'comedy',
                'drama', 'драма' => 'drama',
                'musical', 'myuzikl', 'мюзикл' => 'musical',
                default => null,
            };
            $isTheatre = $theatreGenre !== null || in_array($category, ['theatre', 'theater', 'teatr', 'театр', 'спектакли', 'spektakli', 'спектакль'], true);
            if ($isConcert) {
                $metrics['concerts']++;
                $genres += $musicGenres;
                if (isset($musicGenres['rock']) || isset($musicGenres['metal']) || isset($musicGenres['punk'])) {
                    $metrics['rock']++;
                }
                foreach (array_unique($visit['artists'] ?? []) as $artist) {
                    $artists[$artist] = ($artists[$artist] ?? 0) + 1;
                }
            }
            if ($isTheatre) {
                $metrics['theatre']++;
                if ($theatreGenre !== null) $theatreGenres[$theatreGenre] = true;
                if (!empty($visit['venue_id'])) $venues[$visit['venue_id']] = true;
            }
            // Calendar and night rules use scheduled local start, never the scan/upload hour.
            if (!empty($visit['local_start'])) {
                $month = substr($visit['local_start'], 0, 7);
                $months[$month] = ($months[$month] ?? 0) + 1;
                $hour = (int) substr($visit['local_start'], 11, 2);
                if ($hour >= 22 || $hour < 6) $metrics['night']++;
            }
            $metrics['categories'] = count($categories);
            $metrics['music_genres'] = count($genres);
            $metrics['theatre_genres'] = count($theatreGenres);
            $metrics['theatre_venues'] = count($venues);
            $metrics['artists'] = count($artists);
            $metrics['same_artist'] = $artists === [] ? 0 : max($artists);
            $metrics['monthly'] = $months === [] ? 0 : max($months);
            foreach ($catalog as $item) {
                if ($metrics[$item['metric']] >= $item['target'] && !isset($earned[$item['id']])) {
                    $earned[$item['id']] = $visit['used_at'];
                }
            }
        }
        return array_map(static function (array $item) use ($metrics, $earned): array {
            $date = $earned[$item['id']] ?? null;
            $hidden = $item['hidden'] && $date === null;
            return [
                'id' => $item['id'],
                'status' => $date !== null ? 'earned' : ($hidden ? 'hidden' : 'progress'),
                'progress' => ['current' => min($item['target'], $metrics[$item['metric']]), 'target' => $item['target']],
                'earnedAt' => $date,
                'hidden' => $hidden,
            ];
        }, $catalog);
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function musicGenre(string $value): ?string
    {
        $value = self::normalize($value);
        // Explicit aliases only: event titles are not reliable genre metadata.
        foreach ([
            'rock' => ['rock', 'рок', 'rok', 'hard rock', 'alternative rock', 'рок-концерты'],
            'metal' => ['metal', 'heavy metal', 'метал', 'металл', 'метал-хеви'],
            'punk' => ['punk', 'punk rock', 'панк', 'панк-рок'],
            'pop' => ['pop', 'поп', 'поп-музыка'],
            'jazz' => ['jazz', 'джаз', 'dzhaz'],
            'classical' => ['classical', 'классика', 'классическая музыка'],
            'electronic' => ['electronic', 'электроника', 'techno', 'house'],
            'hip-hop' => ['hip-hop', 'hip hop', 'rap', 'рэп', 'хип-хоп'],
            'folk' => ['folk', 'фолк', 'народная музыка'],
            'blues' => ['blues', 'блюз'],
        ] as $genre => $aliases) {
            if (in_array($value, $aliases, true)) return $genre;
        }
        return null;
    }
}
