<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where a session was opened or closed from (ТЗ §5, §6).
 *
 * A value object rather than three more nullable parameters on `SessionIssuer`,
 * because the same three facts are needed when a session is issued and when it is
 * ended, and because the column widths are a real constraint rather than a
 * formality: `user_agent` is `VARCHAR(1024)` and `device_name` is `VARCHAR(255)`,
 * and a client is free to send a `User-Agent` longer than either. Truncation
 * happens here, once, so no caller has to remember it — the alternative is a
 * database error on login for the one client whose header is long.
 *
 * Not in `Domain/`: this reads an HTTP request, and the domain is guarded
 * framework-free.
 */
final class ClientContext
{
    /** `user_sessions.user_agent` / `login_logs.user_agent`. */
    public const USER_AGENT_MAX = 1024;

    /** `user_sessions.device_name`. */
    public const DEVICE_NAME_MAX = 255;

    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $deviceName = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            ipAddress: self::clean($request->ip(), 45),
            userAgent: self::clean($request->userAgent(), self::USER_AGENT_MAX),
            deviceName: self::clean($request->header('X-Device-Name'), self::DEVICE_NAME_MAX),
        );
    }

    /** An empty header means "not recorded", which is not the same as an empty string. */
    private static function clean(?string $value, int $max): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $max, '');
    }
}
