<?php
/**
 * 極簡 ZIP 產生器（Word .docx / ODF .odt 都是 ZIP 包 XML）。
 * 不用 ZipArchive：那是 PHP 的 zip 擴充，官方 Docker 映像與多數主機預設沒有裝；這裡只需要 zlib（gzdeflate / crc32）。
 * 只寫不讀、全部在記憶體；檔案依加入順序排列（ODF 規定 `mimetype` 必須是第一個且不壓縮）。
 */
final class ZipWriter {
  /** @var array<int,array{name:string,data:string,store:bool}> */
  private array $files = [];

  public function add(string $name, string $data, bool $store = false): void {
    $this->files[] = ['name' => $name, 'data' => $data, 'store' => $store];
  }

  public function build(): string {
    [$dosTime, $dosDate] = self::dosTime(time());
    $out = ''; $central = '';
    foreach ($this->files as $f) {
      $crc = crc32($f['data']);
      $method = $f['store'] ? 0 : 8;
      $body = $f['store'] ? $f['data'] : gzdeflate($f['data'], 6);
      $offset = strlen($out);
      $name = $f['name'];
      // 0x0800 = 檔名為 UTF-8
      $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($body), strlen($f['data']), strlen($name), 0) . $name . $body;
      $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($body), strlen($f['data']),
                       strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    $n = count($this->files);
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
  }

  private static function dosTime(int $ts): array {
    $d = getdate($ts);
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            ((max(1980, $d['year']) - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
  }
}
