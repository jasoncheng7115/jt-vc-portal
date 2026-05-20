<?php
/**
 * 共用 JSON 檔讀寫，含簡易檔案鎖避免並發寫入互相覆蓋。
 * （A10：例外處理 —— 讀失敗回預設值而非崩潰；寫用 LOCK_EX 序列化。）
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

  public static function write(string $file, $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return @file_put_contents($file, $json, LOCK_EX) !== false;
  }

  /** append 一行（JSONL）。 */
  public static function appendLine(string $file, array $obj): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
  }
}
