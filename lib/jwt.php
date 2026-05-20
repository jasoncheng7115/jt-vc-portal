<?php
class JWT {
  private static function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }

  /** RS256：用私鑰檔簽（JaaS 用）。 */
  public static function encode($header, $payload, $keyPath) {
    $h = self::b64url(json_encode($header));
    $p = self::b64url(json_encode($payload));
    $data = "$h.$p";
    $privateKey = file_get_contents($keyPath);
    $signature = '';
    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return "$data." . self::b64url($signature);
  }

  /** HS256：用共享密鑰簽（自建 Jitsi Meet 常用）。 */
  public static function encodeHS256($header, $payload, string $secret) {
    $h = self::b64url(json_encode($header));
    $p = self::b64url(json_encode($payload));
    $data = "$h.$p";
    $sig = hash_hmac('sha256', $data, $secret, true);
    return "$data." . self::b64url($sig);
  }
}
