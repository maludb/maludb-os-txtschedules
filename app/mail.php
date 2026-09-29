<?php
declare(strict_types=1);

/**
 * Send one email via MaluMail (malumail-send). Returns the decoded API response; throws on
 * transport errors and non-2xx. The notifications worker (slice 6) is the only caller. MALUMAIL_API_URL overrides
 * the address (the proofs point it at a stub; production leaves it out).
 */
function malumail_send(array $mail): array
{
    $key = (string) env('MALUMAIL_API_KEY', '');
    if ($key === '') {
        throw new RuntimeException('MALUMAIL_API_KEY is not configured.');
    }
    $ch = curl_init(rtrim((string) env('MALUMAIL_API_URL', 'https://api.malumail.com'), '/') . '/v1/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($mail, JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $body === false) {
        throw new RuntimeException('MaluMail transport error.');
    }
    $decoded = json_decode((string) $body, true);
    if ($status !== 200) {
        throw new RuntimeException("MaluMail send failed ({$status}): " . ($decoded['error'] ?? 'unknown'));
    }
    return is_array($decoded) ? $decoded : [];
}
