<?php
/**
 * RFC 6238 TOTP（相容 Google Authenticator / Authy）。
 * 純 PHP，無外部相依。
 */
class Totp {
  const PERIOD = 30;
  const DIGITS = 6;
  const ALGO   = 'sha1';
  const B32    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

  /** 產生 base32 secret（預設 20 bytes → 32 字元）。 */
  public static function generateSecret(int $bytes = 20): string {
    $raw = random_bytes($bytes);
    return self::base32encode($raw);
  }

  /** 驗證 code，允許前後 $window 個時間窗（時鐘漂移容忍）。 */
  public static function verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== self::DIGITS) return false;
    $t = (int) floor(time() / self::PERIOD);
    for ($i = -$window; $i <= $window; $i++) {
      if (hash_equals(self::at($secret, $t + $i), $code)) return true;
    }
    return false;
  }

  /** 取得某個時間計數的 code。 */
  public static function at(string $secret, int $counter): string {
    $key = self::base32decode($secret);
    $bin = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
    $hash = hash_hmac(self::ALGO, $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = unpack('N', $part)[1] & 0x7FFFFFFF;
    $otp = $value % (10 ** self::DIGITS);
    return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
  }

  /** otpauth:// URI（給 QR Code 用）。 */
  public static function uri(string $secret, string $label, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=' . strtoupper(self::ALGO)
         . '&digits=' . self::DIGITS
         . '&period=' . self::PERIOD;
  }

  public static function base32encode(string $data): string {
    $bits = '';
    foreach (str_split($data) as $c) {
      $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
      $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
      $out .= self::B32[bindec($chunk)];
    }
    return $out;
  }

  public static function base32decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
      $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
      if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
  }
}
