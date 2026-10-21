<?php

namespace App\Services;

use App\Models\Athlete;

/**
 * Signed athlete QR codes. Format: EVT2.<base64url payload>.<signature>
 * The payload holds athlete id, club id and issue time. Only the backend
 * knows the signing key, so the app can't forge codes.
 */
class AthleteQrCode
{
    private const PREFIX = 'EVT2';

    public static function generate(Athlete $athlete): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'id' => (int) $athlete->id,
            'cid' => (int) $athlete->club_id,
            't' => time(),
        ]));

        return self::PREFIX . '.' . $payload . '.' . self::sign($payload, self::secret(), 16);
    }

    /** Returns the athlete id for a valid code, null otherwise. */
    public static function verify(string $code): ?int
    {
        $parts = explode('.', trim($code));
        if (count($parts) !== 3) {
            return null;
        }
        [$prefix, $payload, $signature] = $parts;

        // Only backend-signed codes. Old app-signed "EVT." badges are rejected.
        if ($prefix !== self::PREFIX || !hash_equals(self::sign($payload, self::secret(), 16), $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payload), true);
        $id = is_array($data) ? ($data['id'] ?? null) : null;

        return is_int($id) ? $id : null;
    }

    private static function secret(): string
    {
        return (string) config('boat_checkin.qr_secret');
    }

    private static function sign(string $data, string $key, int $length): string
    {
        return substr(hash_hmac('sha256', $data, $key), 0, $length);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
