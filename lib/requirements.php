<?php
/**
 * 執行環境檢查：必要的 PHP 擴充與隨附檔案。
 * Docker 映像一律齊全；「直接安裝（Apache + PHP）」的站台升級新版時可能缺少新功能才用到的擴充，
 * 系統設定頁會列出缺了什麼、影響哪些功能，而不是等到使用時才出 500。清單與 README「系統需求」一致。
 */
final class Requirements {
  /** 擴充 → [檢查函式 / 類別, 用途] */
  public static function checks(): array {
    return [
      'openssl'  => [function_exists('openssl_sign'), t('JaaS 簽章、單一登入（SSO）驗證')],
      'curl'     => [function_exists('curl_init'), t('錄影調閱、逐字稿與摘要、單一登入（SSO）')],
      'mbstring' => [function_exists('mb_substr'), t('中日文字串處理')],
      'fileinfo' => [class_exists('finfo'), t('站台 logo 上傳')],
      'json'     => [function_exists('json_encode'), t('所有資料存取')],
      'zlib'     => [function_exists('gzcompress'), t('會議記錄匯出 PDF / DOCX / ODT')],
    ];
  }

  /** 缺少的項目：[名稱 => 用途]（空陣列＝齊全）。 */
  public static function missing(): array {
    $m = [];
    foreach (self::checks() as $ext => [$ok, $use]) if (!$ok) $m['php-' . $ext] = $use;
    if (!is_readable(__DIR__ . '/fonts/NotoSansTC-Regular.ttf')) $m['lib/fonts/NotoSansTC-Regular.ttf'] = t('會議記錄匯出 PDF');
    return $m;
  }

  /** 逐字稿背景排程：啟用逐字稿後，排程每分鐘會更新這個檔案的時間。回傳最後執行時間（0＝從沒跑過）。 */
  public static function workerLastRun(): int {
    $f = DATA_DIR . '/transcribe-worker.lock';
    clearstatcache(true, $f);
    return is_file($f) ? (int)filemtime($f) : 0;
  }
}
