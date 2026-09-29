<?php
/**
 * 極簡 PDF 產生器：內嵌 TrueType 字型子集（中日文可正常顯示、複製、搜尋），只做會議記錄需要的排版。
 *
 * 為什麼自己寫：本專案零外部 PHP 相依（見 CLAUDE.md），容器也不裝 LibreOffice / Chromium（數百 MB）；
 * 只靠 PHP 內建的 zlib。字型用 Noto Sans TC（SIL OFL 1.1，`lib/fonts/`），每份 PDF 只嵌入用到的字形（通常幾十 KB）。
 *
 * - 字型：CIDFontType2 + Identity-H，CID = 字形編號（CIDToGIDMap Identity）；子集保留原本的字形編號，
 *   沒用到的字形留空（loca 指向長度 0），所以不必重排編號；附 ToUnicode 讓複製 / 搜尋得到原字。
 * - 粗體：同一個字型以「填色＋描邊」(Tr 2) 加粗，不必再多帶一個 7 MB 的粗體字型檔。
 * - 缺字（字型裡沒有的字，例如韓文）會顯示成空框，不會壞檔。
 */
final class TtfFont {
  public int $upm = 1000;
  public int $ascent = 880; public int $descent = -120; public array $bbox = [0, -120, 1000, 880];
  private string $d;
  private array $tables = [];
  private array $cmap = [];
  private array $adv = [];
  private int $numGlyphs = 0;
  private int $locFmt = 0;
  /** @var array<int,int> 用到的字形 → 對應的 Unicode（ToUnicode 用） */
  public array $used = [0 => 0];

  public function __construct(string $path) {
    $d = @file_get_contents($path);
    if ($d === false || strlen($d) < 12) throw new RuntimeException('font not readable: ' . basename($path));
    $this->d = $d;
    $n = $this->u16(4);
    for ($i = 0; $i < $n; $i++) {
      $p = 12 + 16 * $i;
      $this->tables[substr($d, $p, 4)] = [$this->u32($p + 8), $this->u32($p + 12)];
    }
    foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $t) if (!isset($this->tables[$t])) throw new RuntimeException("font lacks $t");
    $h = $this->tables['head'][0];
    $this->upm = $this->u16($h + 18);
    $this->bbox = [$this->s16($h + 36), $this->s16($h + 38), $this->s16($h + 40), $this->s16($h + 42)];
    $this->locFmt = $this->s16($h + 50);
    $hh = $this->tables['hhea'][0];
    $this->ascent = $this->s16($hh + 4); $this->descent = $this->s16($hh + 6);
    $nhm = $this->u16($hh + 34);
    $this->numGlyphs = $this->u16($this->tables['maxp'][0] + 4);
    $hm = $this->tables['hmtx'][0];
    $last = 0;
    for ($g = 0; $g < $this->numGlyphs; $g++) { if ($g < $nhm) $last = $this->u16($hm + 4 * $g); $this->adv[$g] = $last; }
    $this->readCmap();
  }

  private function u16(int $p): int { return unpack('n', $this->d, $p)[1]; }
  private function s16(int $p): int { $v = $this->u16($p); return $v >= 0x8000 ? $v - 0x10000 : $v; }
  private function u32(int $p): int { return unpack('N', $this->d, $p)[1]; }

  private function readCmap(): void {
    $c = $this->tables['cmap'][0];
    $n = $this->u16($c + 2); $f12 = null; $f4 = null;
    for ($i = 0; $i < $n; $i++) {
      $pid = $this->u16($c + 4 + 8 * $i); $eid = $this->u16($c + 6 + 8 * $i); $off = $c + $this->u32($c + 8 + 8 * $i);
      $fmt = $this->u16($off);
      if ($fmt === 12 && ($pid === 3 && $eid === 10 || $pid === 0)) $f12 = $off;
      if ($fmt === 4 && ($pid === 3 && $eid === 1 || $pid === 0)) $f4 = $off;
    }
    if ($f12 !== null) {
      $ng = $this->u32($f12 + 12);
      for ($i = 0; $i < $ng; $i++) {
        $p = $f12 + 16 + 12 * $i; $s = $this->u32($p); $e = $this->u32($p + 4); $g = $this->u32($p + 8);
        for ($u = $s; $u <= $e; $u++) $this->cmap[$u] = $g + $u - $s;
      }
      return;
    }
    if ($f4 === null) throw new RuntimeException('font has no unicode cmap');
    $seg = $this->u16($f4 + 6) >> 1;
    $ends = $f4 + 14; $starts = $ends + 2 * $seg + 2; $deltas = $starts + 2 * $seg; $ranges = $deltas + 2 * $seg;
    for ($i = 0; $i < $seg; $i++) {
      $e = $this->u16($ends + 2 * $i); $s = $this->u16($starts + 2 * $i); $dl = $this->s16($deltas + 2 * $i); $ro = $this->u16($ranges + 2 * $i);
      for ($u = $s; $u <= $e && $u !== 0xFFFF; $u++) {
        if ($ro === 0) $g = ($u + $dl) & 0xFFFF;
        else { $g = $this->u16($ranges + 2 * $i + $ro + 2 * ($u - $s)); if ($g !== 0) $g = ($g + $dl) & 0xFFFF; }
        if ($g) $this->cmap[$u] = $g;
      }
    }
  }

  /** 字串 → 字形編號陣列（順便記下用到的字形）。 */
  public function glyphs(string $s): array {
    $out = [];
    foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
      $u = mb_ord($ch, 'UTF-8');
      $g = $this->cmap[$u] ?? 0;
      if (!isset($this->used[$g]) || $this->used[$g] === 0) $this->used[$g] = $g ? $u : 0;
      $out[] = $g;
    }
    return $out;
  }
  /** 單字寬（1/1000 字高）。 */
  public function charWidth(string $ch): float {
    $g = $this->cmap[mb_ord($ch, 'UTF-8')] ?? 0;
    return ($this->adv[$g] ?? $this->upm) * 1000 / $this->upm;
  }
  public function glyphWidth(int $g): int { return (int)round(($this->adv[$g] ?? $this->upm) * 1000 / $this->upm); }

  private function glyphRange(int $g): array {
    $l = $this->tables['loca'][0];
    if ($this->locFmt === 0) return [$this->u16($l + 2 * $g) * 2, $this->u16($l + 2 * $g + 2) * 2];
    return [$this->u32($l + 4 * $g), $this->u32($l + 4 * $g + 4)];
  }

  /** 子集字型檔：只留用到的字形（含複合字形引用的元件），其餘清空；表格只帶顯示需要的。 */
  public function subset(): string {
    $glyf = $this->tables['glyf'][0];
    $keep = []; $todo = array_keys($this->used);
    while ($todo) {
      $g = array_pop($todo);
      if (isset($keep[$g]) || $g >= $this->numGlyphs) continue;
      $keep[$g] = true;
      [$a, $b] = $this->glyphRange($g);
      if ($b - $a < 10 || $this->s16($glyf + $a) >= 0) continue;
      $p = $glyf + $a + 10;   // 複合字形：逐一找出元件
      do {
        $flags = $this->u16($p); $todo[] = $this->u16($p + 2);
        $p += 4 + (($flags & 0x1) ? 4 : 2);
        if ($flags & 0x8) $p += 2; elseif ($flags & 0x40) $p += 4; elseif ($flags & 0x80) $p += 8;
      } while ($flags & 0x20);
    }
    $newGlyf = ''; $loca = '';
    for ($g = 0; $g < $this->numGlyphs; $g++) {
      $loca .= pack('N', strlen($newGlyf));
      if (!isset($keep[$g])) continue;
      [$a, $b] = $this->glyphRange($g);
      $newGlyf .= substr($this->d, $glyf + $a, $b - $a);
      if (strlen($newGlyf) % 4) $newGlyf .= str_repeat("\0", 4 - strlen($newGlyf) % 4);
    }
    $loca .= pack('N', strlen($newGlyf));
    $tbl = fn(string $t) => substr($this->d, $this->tables[$t][0], $this->tables[$t][1]);
    $head = $tbl('head');
    $head = substr_replace($head, "\0\0\0\0", 8, 4);        // checkSumAdjustment
    $head = substr_replace($head, pack('n', 1), 50, 2);      // indexToLocFormat = long
    $out = ['head' => $head, 'hhea' => $tbl('hhea'), 'maxp' => $tbl('maxp'), 'hmtx' => $tbl('hmtx'), 'loca' => $loca, 'glyf' => $newGlyf];
    foreach (['cvt ', 'fpgm', 'prep'] as $t) if (isset($this->tables[$t])) $out[$t] = $tbl($t);
    ksort($out, SORT_STRING);
    $n = count($out); $es = (int)floor(log($n, 2)); $sr = (2 ** $es) * 16;
    $dir = pack('Nnnnn', 0x00010000, $n, $sr, $es, $n * 16 - $sr);
    $off = 12 + 16 * $n; $body = '';
    foreach ($out as $t => $data) {
      $pad = $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
      $sum = 0; foreach (unpack('N*', $pad) as $w) $sum = ($sum + $w) & 0xFFFFFFFF;
      $dir .= $t . pack('NNN', $sum, $off + strlen($body), strlen($data));
      $body .= $pad;
    }
    return $dir . $body;
  }
}

final class PdfWriter {
  public const W = 595.28, H = 841.89;
  public float $ml = 48, $mr = 48, $mt = 54, $mb = 58;
  public float $y = 0;
  private TtfFont $font;
  private array $pages = [];
  private string $cur = '';
  /** @var callable|null 頁尾：fn(PdfWriter $pdf, int $page, int $total) */
  public $footer = null;
  public string $title = '';

  public function __construct(TtfFont $font) { $this->font = $font; $this->addPage(); }

  public function width(): float { return self::W - $this->ml - $this->mr; }
  public function addPage(): void {
    if ($this->cur !== '' || $this->pages) $this->pages[] = $this->cur;
    $this->cur = ''; $this->y = self::H - $this->mt;
  }
  /** 剩下的高度不夠放 $h 就換頁；回傳是否換了頁。 */
  public function ensure(float $h): bool {
    if ($this->y - $h < $this->mb) { $this->addPage(); return true; }
    return false;
  }

  public function textWidth(string $s, float $size): float {
    $w = 0.0; foreach (mb_str_split($s, 1, 'UTF-8') as $ch) $w += $this->font->charWidth($ch);
    return $w * $size / 1000;
  }

  /** 斷行：中日文逐字可斷、英數以字為單位；行首不放「，。」等標點（擠進上一行）。 */
  public function wrap(string $s, float $size, float $maxW): array {
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $s) as $para) {
      preg_match_all('/[A-Za-z0-9\x{00C0}-\x{024F}_\-\.\/:@#%&+=?!,;\'"()\[\]]+|\s+|./u', $para, $m);
      $line = ''; $lw = 0.0;
      foreach ($m[0] as $tok) {
        $tw = $this->textWidth($tok, $size);
        if ($lw + $tw <= $maxW || $line === '') {
          if ($tw > $maxW && $line === '') {   // 單一長字串超過整行：逐字切
            foreach (mb_str_split($tok, 1, 'UTF-8') as $ch) {
              $cw = $this->textWidth($ch, $size);
              if ($lw + $cw > $maxW && $line !== '') { $lines[] = $line; $line = ''; $lw = 0; }
              $line .= $ch; $lw += $cw;
            }
            continue;
          }
          $line .= $tok; $lw += $tw; continue;
        }
        if (preg_match('/^[，。、：；！？）」』】〉》,.:;!?)\]]$/u', $tok)) { $line .= $tok; $lines[] = $line; $line = ''; $lw = 0; continue; }   // i18n-ignore
        $lines[] = rtrim($line);
        $tok = ltrim($tok); $line = $tok; $lw = $this->textWidth($tok, $size);
      }
      if ($line !== '' || !$lines || $para === '') $lines[] = rtrim($line);
    }
    return $lines;
  }

  private static function rgb(string $hex): string {
    $hex = ltrim($hex, '#');
    return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
  }

  public function text(float $x, float $y, string $s, float $size, string $color = '#0f172a', bool $bold = false): void {
    if ($s === '') return;
    $hex = ''; foreach ($this->font->glyphs($s) as $g) $hex .= sprintf('%04X', $g);
    $c = self::rgb($color);
    $mode = $bold ? sprintf('2 Tr %.2F w %s RG ', $size * 0.04, $c) : '0 Tr ';
    $this->cur .= sprintf("BT %s rg %s/F1 %.2F Tf %.2F %.2F Td <%s> Tj ET\n", $c, $mode, $size, $x, $y, $hex);
  }
  public function rect(float $x, float $y, float $w, float $h, string $color, float $radius = 0): void {
    $c = self::rgb($color);
    if ($radius <= 0) { $this->cur .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", $c, $x, $y, $w, $h); return; }
    $r = min($radius, $h / 2, $w / 2); $k = 0.5523 * $r;
    $this->cur .= sprintf("%s rg %.2F %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c f\n",
      $c, $x + $r, $y, $x + $w - $r, $y, $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r, $x + $w, $y + $h - $r,
      $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h, $x + $r, $y + $h, $x + $r - $k, $y + $h, $x, $y + $h - $r + $k,
      $x, $y + $h - $r, $x, $y + $r, $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y);
  }
  public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $w = 0.5): void {
    $this->cur .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::rgb($color), $w, $x1, $y1, $x2, $y2);
  }

  public function output(): string {
    $pages = $this->pages; $pages[] = $this->cur;
    $total = count($pages);
    if ($this->footer) {
      foreach ($pages as $i => $c) { $this->cur = ''; ($this->footer)($this, $i + 1, $total); $pages[$i] = $c . $this->cur; }
    }
    $objs = [];
    $add = function (string $body) use (&$objs): int { $objs[] = $body; return count($objs); };
    $stream = function (string $data, string $extra = '') { $z = gzcompress($data, 6); return '<< /Length ' . strlen($z) . " /Filter /FlateDecode $extra>>\nstream\n" . $z . "\nendstream"; };

    // 字型：先排版完才知道用了哪些字形
    $f = $this->font;
    $ff = $f->subset();
    $fontFile = $add($stream($ff, '/Length1 ' . strlen($ff) . ' '));
    $tag = substr(strtoupper(preg_replace('/[^A-Z]/i', '', base64_encode(md5($ff, true)))) . 'AAAAAA', 0, 6);
    $sc = 1000 / $f->upm;
    $desc = $add(sprintf('<< /Type /FontDescriptor /FontName /%s+NotoSansTC-Regular /Flags 4 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
      $tag, $f->bbox[0] * $sc, $f->bbox[1] * $sc, $f->bbox[2] * $sc, $f->bbox[3] * $sc, $f->ascent * $sc, $f->descent * $sc, $f->ascent * $sc, $fontFile));
    $gids = array_keys($f->used); sort($gids);
    $w = ''; foreach ($gids as $g) $w .= $g . ' [' . $f->glyphWidth($g) . '] ';
    $cid = $add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$tag+NotoSansTC-Regular /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $desc 0 R /DW 1000 /W [ $w] /CIDToGIDMap /Identity >>");
    $cm = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
    $pairs = array_filter($f->used, fn($u) => $u > 0);
    foreach (array_chunk($pairs, 100, true) as $chunk) {
      $cm .= count($chunk) . " beginbfchar\n";
      foreach ($chunk as $g => $u) $cm .= sprintf('<%04X> <%s>', $g, strtoupper(bin2hex(mb_convert_encoding(mb_chr($u, 'UTF-8'), 'UTF-16BE', 'UTF-8')))) . "\n";
      $cm .= "endbfchar\n";
    }
    $cm .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    $toUni = $add($stream($cm));
    $font = $add("<< /Type /Font /Subtype /Type0 /BaseFont /$tag+NotoSansTC-Regular /Encoding /Identity-H /DescendantFonts [$cid 0 R] /ToUnicode $toUni 0 R >>");

    $pagesId = count($objs) + 1 + 2 * $total;   // 頁面物件之後才是 Pages
    $kids = [];
    foreach ($pages as $c) {
      $cs = $add($stream($c));
      $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>', $pagesId, self::W, self::H, $font, $cs));
    }
    $add('<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . "] /Count $total >>");
    $catalog = $add("<< /Type /Catalog /Pages $pagesId 0 R >>");
    $info = $add('<< /Title <FEFF' . strtoupper(bin2hex(mb_convert_encoding($this->title, 'UTF-16BE', 'UTF-8'))) . '> /Producer (jt-vc-portal) /CreationDate (D:' . date('YmdHis') . ') >>');

    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"; $xref = [];
    foreach ($objs as $i => $o) { $xref[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($xref as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$x\n%%EOF\n";
  }
}
