<?php
/**
 * 多帳號使用者儲存（OWASP A07）。/var/jaas-data/users.json
 *   - 角色：admin（看全部）/ host（只看自己建的會議室）
 *   - 密碼：password_hash()（bcrypt）
 *   - 2FA：TOTP secret（base32），totp_enabled 開關
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/store.php';

class Users {
  const FILE = DATA_DIR . '/users.json';
  const ROLES = ['admin', 'host'];

  public static function all(): array {
    $d = Store::read(self::FILE, ['users' => []]);
    return $d['users'] ?? [];
  }

  private static function saveAll(array $users): void {
    Store::write(self::FILE, ['users' => array_values($users)]);
  }

  /**
   * 首次無使用者時建立第一個 admin。密碼來源：環境變數 JTVC_ADMIN_PASSWORD；
   * 若未提供則自動產生隨機密碼，並寫入 DATA_DIR/INITIAL_ADMIN_PASSWORD.txt（首次登入後請刪除）。
   * 設定檔不再保存任何明文密碼。
   */
  public static function bootstrap(): void {
    $users = self::all();
    if (!empty($users)) return;

    $password = BOOTSTRAP_ADMIN_PASSWORD;
    $generated = false;
    if ($password === '') {
      $password = self::randomPassword();
      $generated = true;
    }
    self::create([
      'username' => BOOTSTRAP_ADMIN_USERNAME,
      'email'    => BOOTSTRAP_ADMIN_EMAIL,
      'password' => $password,
      'role'     => 'admin',
    ]);
    if ($generated) {
      $note = "初始管理員帳號已建立\n"
            . "username: " . BOOTSTRAP_ADMIN_USERNAME . "\n"
            . "email:    " . BOOTSTRAP_ADMIN_EMAIL . "\n"
            . "password: " . $password . "\n\n"
            . "請立即登入並到 /profile 變更密碼，然後刪除本檔。\n";
      @file_put_contents(DATA_DIR . '/INITIAL_ADMIN_PASSWORD.txt', $note);
    }
  }

  private static function randomPassword(int $len = 20): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
  }

  public static function find(?string $id): ?array {
    if (!$id) return null;
    foreach (self::all() as $u) {
      if (($u['id'] ?? '') === $id) return $u;
    }
    return null;
  }

  /** 用 email 或 username 找（登入用，大小寫不敏感）。 */
  public static function findByLogin(string $login): ?array {
    $login = strtolower(trim($login));
    foreach (self::all() as $u) {
      if (strtolower($u['email'] ?? '') === $login) return $u;
      if (strtolower($u['username'] ?? '') === $login) return $u;
    }
    return null;
  }

  public static function create(array $data): array {
    $users = self::all();
    $username = trim($data['username'] ?? '');
    $user = [
      'id'            => 'u_' . bin2hex(random_bytes(8)),
      'username'      => $username,
      'display_name'  => trim($data['display_name'] ?? '') ?: $username,
      'email'         => trim($data['email'] ?? ''),
      'password_hash' => password_hash($data['password'] ?? bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
      'role'          => in_array($data['role'] ?? 'host', self::ROLES, true) ? $data['role'] : 'host',
      'totp_secret'   => null,
      'totp_enabled'  => false,
      'disabled'      => false,
      'created_at'    => time(),
    ];
    $users[] = $user;
    self::saveAll($users);
    return $user;
  }

  public static function update(string $id, array $fields): ?array {
    $users = self::all();
    $found = null;
    foreach ($users as &$u) {
      if (($u['id'] ?? '') !== $id) continue;
      foreach (['username', 'display_name', 'email', 'role', 'totp_secret', 'totp_enabled', 'disabled'] as $k) {
        if (array_key_exists($k, $fields)) $u[$k] = $fields[$k];
      }
      if (!empty($fields['password'])) {
        $u['password_hash'] = password_hash($fields['password'], PASSWORD_DEFAULT);
      }
      if (isset($u['role']) && !in_array($u['role'], self::ROLES, true)) $u['role'] = 'host';
      $found = $u;
      break;
    }
    unset($u);
    if ($found) self::saveAll($users);
    return $found;
  }

  public static function delete(string $id): bool {
    $users = self::all();
    $n = count($users);
    $users = array_filter($users, fn($u) => ($u['id'] ?? '') !== $id);
    if (count($users) === $n) return false;
    self::saveAll($users);
    return true;
  }

  public static function verifyPassword(array $user, string $password): bool {
    return password_verify($password, $user['password_hash'] ?? '');
  }

  public static function adminCount(): int {
    return count(array_filter(self::all(), fn($u) => ($u['role'] ?? '') === 'admin' && empty($u['disabled'])));
  }
}
