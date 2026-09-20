<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

/**
 * How a scan reached the server.
 *
 * ONLINE       — the device had network and asked before letting anyone in. The
 *                server's answer is the answer.
 *
 * OFFLINE_SYNC — the device was offline at the door, decided locally from its
 *                bundle, and is now uploading what it did. THE DEVICE MAY ALREADY
 *                HAVE LET SOMEONE IN. That is the whole difficulty: the server is
 *                no longer deciding whether to admit, it is reconciling a decision
 *                that was made hours ago against a ticket whose status may have
 *                changed since the bundle was generated.
 *
 * Treating those two as the same operation is the bug this class exists to
 * prevent: an offline scan replayed as if it were online would let a revoked
 * ticket through, because "admit" would be evaluated against a stale snapshot.
 */
final class ScanMode
{
    public const ONLINE = 'online';
    public const OFFLINE_SYNC = 'offline_sync';

    public static function all(): array
    {
        return [self::ONLINE, self::OFFLINE_SYNC];
    }
}
