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
  /** 主持人 JWT 效期：12 小時（涵蓋長會議中途斷線重連；Jitsi 重連時會重新驗 token）。 */
  const HOST_JWT_TTL  = 43200;
  /** 來賓 JWT 效期：6 小時。 */
  const GUEST_JWT_TTL = 21600;
  /** 加購 / 計費功能一律關閉（來賓與主持人共用；主持人另外開啟錄影）。 */
  const FEATURES_OFF = ['recording' => false, 'livestreaming' => false, 'transcription' => false, 'outbound-call' => false];

  public static function cfg(): array { return Settings::getJaas(); }
  public static function mode(): string { return self::cfg()['mode']; }
  public static function apiDomain(): string { return self::cfg()['domain']; }

  /**
   * Jitsi IFrame API（external_api.js）改用內附釘版副本 + SRI（A03 供應鏈）：
   * 不再於執行時從第三方網域載入可被任意更新的腳本。更新方式：tools/update-jitsi-external-api.sh。
   * 8x8 各租戶路徑下的 external_api.js 與通用版內容相同；自建 Jitsi 亦相容（IFrame API 向下相容）。
   */
  const EXTERNAL_API_PATH = '/assets/vendor/jitsi-external-api.js';
  const EXTERNAL_API_SRI = 'sha384-qaPd4XDSHemAooT+E2Qwi2YVVGTCOcsRxpDJhzz6w9a6h/vmKZJb/3+/xjlMtpPA';

  public static function scriptUrl(): string {
    return self::EXTERNAL_API_PATH . '?v=' . substr(self::EXTERNAL_API_SRI, 7, 12);
  }

  public static function scriptSri(): string { return self::EXTERNAL_API_SRI; }

  /** Jitsi 會議 iframe 的來源（CSP frame-src 用）。 */
  public static function frameOrigin(): string {
    $d = preg_replace('/[^A-Za-z0-9.\-:]/', '', self::cfg()['domain']);
    return $d !== '' ? 'https://' . $d : "'none'";
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
  public static function makeJwt(string $room, array $user, array $features = [], int $ttl = self::HOST_JWT_TTL): string {
    $c = self::cfg();

    if ($c['mode'] === 'jaas') {
      $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $c['kid']];
      $payload = [
        'aud' => 'jitsi', 'iss' => 'chat', 'sub' => $c['app_id'],
        'room' => $room, 'nbf' => time() - 10, 'exp' => time() + $ttl,
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
    // sub 預設 '*'（單網域 docker-jitsi-meet 非租戶模式：sub 須為 '*' 或租戶名，
    // 用公開網域當 sub 會被 prosody token 驗證拒絕）。需租戶時於設定填 sh_sub。
    $payload = [
      'aud' => $aud,
      'iss' => $aud,
      'sub' => ($c['sh_sub'] !== '' ? $c['sh_sub'] : '*'),
      'room' => $room !== '' ? $room : '*',
      'nbf' => time() - 10,
      'exp' => time() + $ttl,
      'context' => ['user' => $user],
    ];
    return JWT::encodeHS256($header, $payload, $c['sh_secret']);
  }
}
