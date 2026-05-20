<?php
/**
 * 抽象化 JaaS（8x8）與自建 Jitsi Meet 兩種接法的差異。
 * 三個頁面（start/meeting/guest）只呼叫這裡，不各自判斷模式。
 *   - scriptUrl()  : external_api.js 來源
 *   - apiDomain()  : new JitsiMeetExternalAPI(<domain>, ...) 的第一參數
 *   - roomName()   : 完整房名（JaaS 需 appId 前綴；自建不用）
 *   - makeJwt()    : 依模式簽 JWT（JaaS=RS256+kid；自建=HS256 或不需）；回 '' 代表不帶 jwt
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/jwt.php';

class Jaas {
  public static function cfg(): array { return Settings::getJaas(); }
  public static function mode(): string { return self::cfg()['mode']; }
  public static function apiDomain(): string { return self::cfg()['domain']; }

  public static function scriptUrl(): string {
    $c = self::cfg();
    return $c['mode'] === 'jaas'
      ? "https://{$c['domain']}/{$c['app_id']}/external_api.js"
      : "https://{$c['domain']}/external_api.js";
  }

  public static function roomName(string $room): string {
    $c = self::cfg();
    return $c['mode'] === 'jaas' ? ($c['app_id'] . '/' . $room) : $room;
  }

  /**
   * 簽 JWT。$user = context.user 陣列（含 name/email/id/moderator）。
   * $features = JaaS 的 context.features（自建忽略）。
   * 回傳 JWT 字串；自建且未啟用 JWT 時回 ''（前端不帶 jwt）。
   */
  public static function makeJwt(string $room, array $user, array $features = []): string {
    $c = self::cfg();

    if ($c['mode'] === 'jaas') {
      $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $c['kid']];
      $payload = [
        'aud' => 'jitsi', 'iss' => 'chat', 'sub' => $c['app_id'],
        'room' => $room, 'exp' => time() + 3600,
        'context' => ['user' => $user] + ($features ? ['features' => $features] : []),
      ];
      return JWT::encode($header, $payload, JWT_PRIVATE_KEY_PATH);
    }

    // 自建 Jitsi Meet
    if (($c['sh_auth'] ?? 'none') !== 'jwt' || $c['sh_secret'] === '') {
      return ''; // 未啟用 token 驗證 → 不帶 jwt
    }
    $aud = $c['app_id'] !== '' ? $c['app_id'] : 'jitsi';
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
      'aud' => $aud,
      'iss' => $aud,
      'sub' => ($c['sh_sub'] !== '' ? $c['sh_sub'] : $c['domain']),
      'room' => $room !== '' ? $room : '*',
      'exp' => time() + 3600,
      'context' => ['user' => $user],
    ];
    return JWT::encodeHS256($header, $payload, $c['sh_secret']);
  }
}
