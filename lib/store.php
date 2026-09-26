<?php
/**
 * 共用 JSON 檔讀寫（A10：例外處理 / 資料完整性）。
 *   - write()：原子寫入（同目錄暫存檔 → rename），讀者永遠不會讀到寫一半或被清空的檔案。
 *   - update()：以旁路鎖檔（<file>.lock）序列化「讀 → 改 → 寫」，避免並發請求互相覆蓋（lost update）。
 *   - read()：讀失敗回預設值而非崩潰。
 * 所有會「讀出後改寫」的持久化資料都必須走 update()，不可自行 read + write。
 */
require_once __DIR__ . '/../config.php';

class Store {
  public static function read(string $file, $default = []) {
    if (!file_exists($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return $default;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $default;
  }

  /** 原子寫入：先寫同目錄暫存檔再 rename 取代（同一檔案系統上 rename 為原子操作）。 */
  public static function write(string $file, $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $json = json_encode($data, $flags);
    if ($json === false) return false;
    return self::writeRaw($file, $json);
  }

  /** 原子寫入原始字串。 */
  public static function writeRaw(string $file, string $content): bool {
    $dir = dirname($file);
    $tmp = @tempnam($dir, '.' . basename($file) . '.');
    if ($tmp === false) return false;
    if (@file_put_contents($tmp, $content) === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
  }

  /**
   * 在排他鎖內執行「讀 → 改 → 寫」。$fn 收到目前資料（陣列），回傳新資料；
   * 回傳 null 表示不需寫回。update() 回傳 $fn 的結果（或未寫回時的原資料）。
   * 若 $fn 需要回傳其他值給呼叫端，可用 by-reference 的 use 變數。
   */
  public static function update(string $file, callable $fn, $default = []) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $lk = @fopen($file . '.lock', 'c');
    if ($lk) @flock($lk, LOCK_EX);
    try {
      $cur = self::read($file, $default);
      $new = $fn($cur);
      if ($new !== null) self::write($file, $new);
      return $new ?? $cur;
    } finally {
      if ($lk) { @flock($lk, LOCK_UN); @fclose($lk); }
    }
  }

  /** append 一行（JSONL）。以檔案本身 LOCK_EX 序列化（pruneLines 使用同一把鎖）。 */
  public static function appendLine(string $file, array $obj): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
  }

  /**
   * 在與 appendLine 相同的檔案鎖內過濾 JSONL 行（保留 $keep 回傳 true 的行），
   * 避免清理時遺失同時 append 進來的記錄。回傳是否有刪除。
   */
  public static function pruneLines(string $file, callable $keep): bool {
    if (!file_exists($file)) return false;
    $fp = @fopen($file, 'r+');
    if (!$fp) return false;
    $changed = false;
    try {
      if (!@flock($fp, LOCK_EX)) return false;
      $kept = [];
      while (($ln = fgets($fp)) !== false) {
        $ln = rtrim($ln, "\r\n");
        if ($ln === '') continue;
        if ($keep($ln)) $kept[] = $ln; else $changed = true;
      }
      if ($changed) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $kept ? implode("\n", $kept) . "\n" : '');
        fflush($fp);
      }
      @flock($fp, LOCK_UN);
    } finally {
      @fclose($fp);
    }
    return $changed;
  }
}
