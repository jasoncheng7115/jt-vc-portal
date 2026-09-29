<?php
/**
 * 會議記錄匯出：PDF / Word（.docx）/ ODF 文字文件（.odt）。
 * 三種格式共用同一份內容（model()）：會議資訊 → 摘要（重點、決議與待辦、事件與影響、風險、未決問題、議題時間軸、誰講了多少）→ 逐字稿，
 * 內容與順序比照網頁；發言者名字套用改名。全部純 PHP 產生，只需要 zlib（見 README「系統需求」）。
 */
require_once __DIR__ . '/zipwriter.php';
require_once __DIR__ . '/pdfwriter.php';

final class TxExport {
  public const FORMATS = ['pdf' => 'application/pdf',
                          'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                          'odt' => 'application/vnd.oasis.opendocument.text'];
  public const FONT = __DIR__ . '/fonts/NotoSansTC-Regular.ttf';
  /** 發言者配色（同網頁 .mt-c* / .mt-b*）。 */
  private const FG = ['2563eb', 'c2410c', '15803d', '7c3aed', 'be123c', '0f766e', 'a16207', '4338ca'];
  private const BG = ['eff6ff', 'fff7ed', 'f0fdf4', 'faf5ff', 'fff1f2', 'f0fdfa', 'fefce8', 'eef2ff'];

  /** 缺少的 PHP 擴充 / 檔案（空陣列＝可以匯出）。 */
  public static function missing(): array {
    $m = [];
    if (!function_exists('gzcompress')) $m[] = 'zlib';
    if (!function_exists('mb_str_split')) $m[] = 'mbstring';
    if (!is_readable(self::FONT)) $m[] = 'lib/fonts/NotoSansTC-Regular.ttf';
    return $m;
  }

  /** 列舉與「標籤：值」的分隔（英文用半形）。 */
  private static function sep(string $kind): string {
    $en = I18n::lang() === 'en';
    return match ($kind) { 'list' => $en ? ', ' : '、', 'colon' => $en ? ': ' : '：', default => $en ? '   ' : '　' };   // i18n-ignore
  }

  private static function mmss(?int $ms): string {
    if ($ms === null) return '';
    $t = intdiv(max(0, $ms), 1000);
    return $t >= 3600 ? sprintf('%d:%02d:%02d', intdiv($t, 3600), intdiv($t, 60) % 60, $t % 60) : sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
  }

  /**
   * 內容區塊：['title',s] ['meta',[[label,value]]] ['h',s] ['p',s] ['warn',s] ['item',kind|null,tag,text,meta,cites]
   *          ['table',[header],[[cells]],[寬度比]] ['segs',[[time,name,text,color]]] ['foot',s]
   */
  public static function model(array $rec, array $tr, ?array $sum, ?array $sess): array {
    $names = (array)($tr['speaker_names'] ?? []); $ovr = (array)($tr['speaker_overrides'] ?? []);
    $order = [];
    $color = function (?string $sp) use (&$order): int {
      if ($sp === null || $sp === '') return -1;
      if (!isset($order[$sp])) $order[$sp] = count($order);
      return $order[$sp] % 8;
    };
    // 顏色依逐字稿出現順序（同網頁）
    foreach ($tr['segments'] as $s) $color((string)($s['speaker'] ?? ''));
    $nameOf = fn(?string $id) => ($id !== null && $id !== '') ? (string)($names[$id] ?? $id) : '';
    $segName = function (array $s) use ($ovr, $nameOf) { $o = (string)($ovr[(string)$s['seq']] ?? ''); return $o !== '' ? $o : $nameOf((string)($s['speaker'] ?? '')); };

    $room = (string)($rec['room'] ?? '');
    $start = (int)($rec['mtime'] ?? 0) - (int)($rec['duration'] ?? 0);
    $dur = (int)($rec['duration'] ?? 0);
    $b = [['title', t('會議記錄') . ($room !== '' ? self::sep('colon') . $room : '')]];
    $meta = [[t('會議室'), $room], [t('時間'), date('Y-m-d H:i', $start) . ' – ' . date('H:i', $start + $dur)], [t('時長'), self::mmss($dur * 1000)]];
    if ($sess && ($sess['owner_name'] ?? '') !== '') $meta[] = [t('主持人'), (string)$sess['owner_name']];
    $people = Transcripts::participantNames($sess);
    if ($people) $meta[] = [t('參與者'), implode(self::sep('list'), $people)];
    $spk = [];
    foreach ($tr['segments'] as $s) if (($n = $segName($s)) !== '') $spk[$n] = true;
    $meta[] = [t('逐字稿'), t('共 {0} 段，{1} 位發言者', [count($tr['segments']), count($spk)])];
    $b[] = ['meta', $meta];

    if ($sum) {
      $cite = function (array $it) use ($nameOf): string {
        $c = array_map(fn($x) => self::mmss(isset($x['start_ms']) ? (int)$x['start_ms'] : null) . (($n = $nameOf($x['speaker_id'] ?? null)) !== '' ? ' ' . $n : ''), (array)($it['citations'] ?? []));
        return $c ? t('出處：') . implode(self::sep('list'), $c) : '';
      };
      $itemMeta = function (array $it) use ($nameOf): string {
        $m = [];
        if (!empty($it['owner'])) $m[] = t('負責：{0}', [preg_replace_callback('/\\bS\\d+\\b/', fn($x) => $nameOf($x[0]), (string)$it['owner'])]);
        if (!empty($it['due_text'])) $m[] = t('期限：{0}', [(string)$it['due_text']]);
        return implode(self::sep('gap'), $m);
      };
      $items = (array)($sum['items'] ?? []);
      $b[] = ['h', t('重點摘要')];
      $b[] = ['p', (string)($sum['summary']['text'] ?? '') ?: t('沒有找到')];
      if (empty($sum['summary']['grounded'] ?? true) && !empty($sum['summary']['unsupported'])) {
        $b[] = ['warn', t('摘要裡有些數字或詞在逐字稿裡找不到，請核對：{0}', [implode(', ', (array)$sum['summary']['unsupported'])])];
      }
      $sec = function (string $title, array $list) use (&$b, $cite, $itemMeta) {
        $b[] = ['h', $title];
        if (!$list) { $b[] = ['p', t('沒有找到')]; return; }
        foreach ($list as [$kind, $it]) {
          $tag = $kind === 'd' ? t('決議') : ($kind === 'a' ? t('待辦') : '');
          $b[] = ['item', $kind, $tag, (string)($it['text'] ?? ''), $itemMeta($it), $cite($it)];
        }
      };
      $wrap = fn(array $l, ?string $k) => array_map(fn($x) => [$k, (array)$x], $l);
      $sec(t('決議與待辦'), array_merge($wrap((array)($items['decisions'] ?? []), 'd'), $wrap((array)($items['actions'] ?? []), 'a')));
      $sec(t('事件與影響'), $wrap((array)($items['impacts'] ?? []), null));
      $sec(t('風險'), $wrap((array)($items['risks'] ?? []), null));
      $sec(t('未決問題'), $wrap((array)($items['questions'] ?? []), null));
      if (!empty($sum['chapters'])) {
        $b[] = ['h', t('議題時間軸')];
        $b[] = ['table', [t('時間'), t('議題'), t('比例')],
          array_map(fn($c) => [self::mmss((int)($c['start_ms'] ?? 0)) . '–' . self::mmss((int)($c['end_ms'] ?? 0)), (string)($c['title'] ?? ''), sprintf('%.1f%%', (float)($c['percentage'] ?? 0))], (array)$sum['chapters']),
          [0.2, 0.65, 0.15]];
      }
      if (!empty($sum['speakers'])) {
        $b[] = ['h', t('誰講了多少')];
        $b[] = ['table', [t('發言者'), t('發言時間'), t('發言次數'), t('字數')],
          array_map(fn($s) => [$nameOf((string)($s['speaker_id'] ?? '')), self::mmss((int)($s['speaking_ms'] ?? 0)) . sprintf(' (%.1f%%)', (float)($s['percentage'] ?? 0)),
                               (string)(int)($s['turn_count'] ?? 0), (int)($s['chars'] ?? 0) . sprintf(' (%.1f%%)', (float)($s['char_pct'] ?? 0))], (array)$sum['speakers']),
          [0.34, 0.26, 0.16, 0.24]];
      }
    }
    $b[] = ['h', t('逐字稿')];
    $b[] = ['segs', array_map(fn($s) => [self::mmss(isset($s['start_ms']) ? (int)$s['start_ms'] : null), $segName($s), (string)$s['text'], $color((string)($s['speaker'] ?? ''))], $tr['segments'])];
    $foot = [];
    if ($sum && ($sum['model'] ?? '') !== '') $foot[] = t('摘要模型：{m}', ['m' => (string)$sum['model']]);
    $foot[] = t('發言者代號（S1、S2…）是語音辨識分出的聲音，不一定是人名。');
    $b[] = ['foot', implode(self::sep('gap'), $foot)];
    return $b;
  }

  public static function render(string $fmt, array $blocks, string $title, string $lang): string {
    return match ($fmt) { 'pdf' => self::pdf($blocks, $title), 'docx' => self::docx($blocks, $title, $lang), 'odt' => self::odt($blocks, $title, $lang) };
  }

  // ------------------------------------------------------------------ PDF
  private static function pdf(array $blocks, string $title): string {
    $pdf = new PdfWriter(new TtfFont(self::FONT));
    $pdf->title = $title;
    $brand = function_exists('site_brand') ? (string)(site_brand()['name'] ?? '') : '';
    $pdf->footer = function (PdfWriter $p, int $n, int $tot) use ($title, $brand) {
      $p->line($p->ml, 40, PdfWriter::W - $p->mr, 40, '#e2e8f0');
      $p->text($p->ml, 28, mb_strimwidth($title, 0, 70, '…', 'UTF-8'), 8, '#94a3b8');
      $s = "$n / $tot"; $p->text(PdfWriter::W - $p->mr - $p->textWidth($s, 8), 28, $s, 8, '#94a3b8');
    };
    $W = $pdf->width(); $x0 = $pdf->ml;
    $para = function (string $s, float $size, string $color, float $x, float $w, float $lh = 1.55, bool $bold = false) use ($pdf) {
      foreach ($pdf->wrap($s, $size, $w) as $ln) { $pdf->ensure($size * $lh); $pdf->y -= $size * $lh; $pdf->text($x, $pdf->y + $size * 0.3, $ln, $size, $color, $bold); }
    };
    foreach ($blocks as $bl) {
      switch ($bl[0]) {
        case 'title':
          $para($bl[1], 18, '#0f172a', $x0, $W, 1.4, true); $pdf->y -= 6; break;
        case 'meta':
          $pdf->y -= 2; $top = $pdf->y; $rows = [];
          foreach ($bl[1] as [$k, $v]) $rows[] = [$k, $pdf->wrap($v, 9.5, $W - 90)];
          $h = 12; foreach ($rows as $r) $h += count($r[1]) * 15;
          $pdf->ensure($h); $top = $pdf->y;
          $pdf->rect($x0, $top - $h, $W, $h, '#f1f5f9', 6);
          $yy = $top - 6;
          foreach ($rows as [$k, $lines]) {
            foreach ($lines as $i => $ln) { $yy -= 15; if ($i === 0) $pdf->text($x0 + 12, $yy + 4, $k, 9.5, '#64748b'); $pdf->text($x0 + 84, $yy + 4, $ln, 9.5, '#0f172a'); }
          }
          $pdf->y = $top - $h - 4; break;
        case 'h':
          $pdf->ensure(60); $pdf->y -= 22;
          $pdf->rect($x0, $pdf->y - 2, 3.5, 15, '#2563eb', 1.5);
          $pdf->text($x0 + 10, $pdf->y + 1, $bl[1], 13, '#0f172a', true);
          $pdf->y -= 8; break;
        case 'p':
          $para($bl[1], 10.5, '#1e293b', $x0, $W); $pdf->y -= 4; break;
        case 'warn':
          $para($bl[1], 9.5, '#b45309', $x0, $W); $pdf->y -= 4; break;
        case 'item':
          [, $kind, $tag, $text, $meta, $cites] = $bl;
          $ind = $tag !== '' ? $pdf->textWidth($tag, 9) + 20 : 12;
          $lines = $pdf->wrap($text, 10.5, $W - $ind);
          $pdf->ensure(16.5 * min(3, count($lines)) + 14);
          $pdf->y -= 6;
          foreach ($lines as $i => $ln) {
            $pdf->ensure(16.5); $pdf->y -= 16.5;
            if ($i === 0) {
              if ($tag !== '') {
                $tw = $pdf->textWidth($tag, 9);
                $pdf->rect($x0, $pdf->y + 0.5, $tw + 10, 14, $kind === 'd' ? '#dcfce7' : '#dbeafe', 3);
                $pdf->text($x0 + 5, $pdf->y + 4.2, $tag, 9, $kind === 'd' ? '#15803d' : '#1d4ed8', true);
              } else {
                $pdf->rect($x0 + 2, $pdf->y + 5.5, 4, 4, '#94a3b8', 2);
              }
            }
            $pdf->text($x0 + $ind, $pdf->y + 3.2, $ln, 10.5, '#0f172a');
          }
          if ($meta !== '') $para($meta, 8.5, '#475569', $x0 + $ind, $W - $ind, 1.5);
          if ($cites !== '') $para($cites, 8.5, '#64748b', $x0 + $ind, $W - $ind, 1.5);
          break;
        case 'table':
          [, $head, $rows, $ratio] = $bl;
          $cw = array_map(fn($r) => $r * $W, $ratio);
          $row = function (array $cells, bool $isHead) use ($pdf, $cw, $x0) {
            $size = 9.5; $wrapped = []; $n = 1;
            foreach ($cells as $i => $c) { $wrapped[$i] = $pdf->wrap((string)$c, $size, $cw[$i] - 12); $n = max($n, count($wrapped[$i])); }
            $h = $n * 14 + 8;
            $pdf->ensure($h);
            if ($isHead) $pdf->rect($x0, $pdf->y - $h, array_sum($cw), $h, '#f1f5f9');
            $x = $x0;
            foreach ($wrapped as $i => $lines) {
              foreach ($lines as $k => $ln) $pdf->text($x + 6, $pdf->y - 4 - 14 * ($k + 1) + 3.5, $ln, $size, $isHead ? '#475569' : '#0f172a', $isHead);
              $x += $cw[$i];
            }
            $pdf->y -= $h;
            $pdf->line($x0, $pdf->y, $x0 + array_sum($cw), $pdf->y, '#e2e8f0');
          };
          $pdf->ensure(60); $pdf->y -= 4; $row($head, true);
          foreach ($rows as $r) { if ($pdf->ensure(22)) $row($head, true); $row($r, false); }
          $pdf->y -= 4; break;
        case 'segs':
          $tw = 40; $nw = 78; $size = 10;
          $pdf->y -= 4;
          foreach ($bl[1] as [$time, $name, $text, $ci]) {
            $tl = $pdf->wrap($text, $size, $W - $tw - $nw - 12);
            $nl = $pdf->wrap($name, 9.5, $nw - 8);
            $n = max(count($tl), count($nl)); $k0 = 0;
            // 長段落跨頁：這頁放得下幾行就先放幾行（至少 2 行，否則整段換頁），其餘接到下一頁
            while ($k0 < $n) {
              $fit = (int)floor(($pdf->y - $pdf->mb - 7) / 15);
              if ($fit < min(2, $n - $k0)) { $pdf->addPage(); continue; }
              $cnt = min($fit, $n - $k0); $h = $cnt * 15 + 7; $top = $pdf->y;
              if ($ci >= 0) $pdf->rect($x0, $top - $h, $W, $h, '#' . self::BG[$ci]);
              if ($k0 === 0) $pdf->text($x0 + 6, $top - 14, $time, 9, '#64748b');
              for ($k = $k0; $k < $k0 + $cnt; $k++) {
                $yy = $top - 14 - 15 * ($k - $k0);
                if (isset($nl[$k])) $pdf->text($x0 + $tw + 6, $yy, $nl[$k], 9.5, $ci >= 0 ? '#' . self::FG[$ci] : '#475569', true);
                if (isset($tl[$k])) $pdf->text($x0 + $tw + $nw + 6, $yy, $tl[$k], $size, '#0f172a');
              }
              $pdf->y -= $h; $k0 += $cnt;
            }
            $pdf->y -= 1.5;
          }
          break;
        case 'foot':
          $pdf->y -= 10; $para($bl[1], 8.5, '#94a3b8', $x0, $W, 1.5); break;
      }
    }
    return $pdf->output();
  }

  // ------------------------------------------------------------------ Word (.docx)
  private static function x(string $s): string {
    // XML 1.0 不允許的控制字元一律去掉，否則 Word / LibreOffice 會說檔案損毀
    return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }
  private static function eaFont(string $lang): string { return str_starts_with($lang, 'ja') ? 'Yu Gothic' : 'Microsoft JhengHei'; }

  private static function docx(array $blocks, string $title, string $lang): string {
    $x = [self::class, 'x'];
    $run = fn(string $t, array $o = []) => '<w:r><w:rPr>' . (!empty($o['b']) ? '<w:b/>' : '') . (isset($o['c']) ? '<w:color w:val="' . $o['c'] . '"/>' : '')
      . (isset($o['sz']) ? '<w:sz w:val="' . $o['sz'] . '"/><w:szCs w:val="' . $o['sz'] . '"/>' : '') . (isset($o['shd']) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['shd'] . '"/>' : '')
      . '</w:rPr><w:t xml:space="preserve">' . $x($t) . '</w:t></w:r>';
    $par = fn(string $runs, string $style = '', string $ppr = '') => '<w:p>' . ($style !== '' || $ppr !== '' ? '<w:pPr>' . ($style !== '' ? '<w:pStyle w:val="' . $style . '"/>' : '') . $ppr . '</w:pPr>' : '') . $runs . '</w:p>';
    $cell = fn(float $w, string $content, string $fill = '') => '<w:tc><w:tcPr><w:tcW w:w="' . (int)$w . '" w:type="dxa"/>' . ($fill !== '' ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '</w:tcPr>' . $content . '</w:tc>';
    $tblStart = function (array $widths) {
      $g = implode('', array_map(fn($w) => '<w:gridCol w:w="' . (int)$w . '"/>', $widths));
      return '<w:tbl><w:tblPr><w:tblW w:w="' . (int)array_sum($widths) . '" w:type="dxa"/><w:tblBorders><w:bottom w:val="single" w:sz="4" w:color="E2E8F0"/><w:insideH w:val="single" w:sz="4" w:color="E2E8F0"/></w:tblBorders><w:tblLayout w:type="fixed"/>'
        . '<w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . $g . '</w:tblGrid>';
    };
    $W = 9638; // A4 減 2cm 邊界（twips）
    $body = '';
    foreach ($blocks as $bl) {
      switch ($bl[0]) {
        case 'title': $body .= $par($run($bl[1]), 'Title'); break;
        case 'meta':
          $body .= $tblStart([1600, $W - 1600]);
          foreach ($bl[1] as [$k, $v]) $body .= '<w:tr>' . $cell(1600, $par($run($k, ['c' => '64748B'])), 'F1F5F9') . $cell($W - 1600, $par($run($v)), 'F1F5F9') . '</w:tr>';
          $body .= '</w:tbl>'; break;
        case 'h': $body .= $par($run($bl[1]), 'Heading1'); break;
        case 'p': $body .= $par($run($bl[1])); break;
        case 'warn': $body .= $par($run($bl[1], ['c' => 'B45309'])); break;
        case 'item':
          [, $kind, $tag, $text, $meta, $cites] = $bl;
          $r = $tag !== '' ? $run(' ' . $tag . ' ', ['b' => true, 'c' => $kind === 'd' ? '15803D' : '1D4ED8', 'shd' => $kind === 'd' ? 'DCFCE7' : 'DBEAFE']) . $run(' ') : $run('• ', ['c' => '94A3B8']);
          $body .= $par($r . $run($text), '', '<w:spacing w:before="120" w:after="20"/>');
          if ($meta !== '') $body .= $par($run($meta, ['c' => '475569', 'sz' => 17]), '', '<w:spacing w:after="0"/><w:ind w:left="240"/>');
          if ($cites !== '') $body .= $par($run($cites, ['c' => '64748B', 'sz' => 17]), '', '<w:ind w:left="240"/>');
          break;
        case 'table':
          [, $head, $rows, $ratio] = $bl;
          $ws = array_map(fn($r) => $r * $W, $ratio);
          $body .= $tblStart($ws) . '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
          foreach ($head as $i => $h) $body .= $cell($ws[$i], $par($run($h, ['b' => true, 'c' => '475569'])), 'F1F5F9');
          $body .= '</w:tr>';
          foreach ($rows as $row) { $body .= '<w:tr>'; foreach ($row as $i => $c) $body .= $cell($ws[$i], $par($run((string)$c))); $body .= '</w:tr>'; }
          $body .= '</w:tbl>' . $par(''); break;
        case 'segs':
          $ws = [800, 1500, $W - 2300];
          $body .= $tblStart($ws) . '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $cell($ws[0], $par($run(t('時間'), ['b' => true, 'c' => '475569'])), 'F1F5F9')
            . $cell($ws[1], $par($run(t('發言者'), ['b' => true, 'c' => '475569'])), 'F1F5F9') . $cell($ws[2], $par($run(t('內容'), ['b' => true, 'c' => '475569'])), 'F1F5F9') . '</w:tr>';
          foreach ($bl[1] as [$time, $name, $text, $ci]) {
            $bg = $ci >= 0 ? strtoupper(self::BG[$ci]) : '';
            $body .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . $cell($ws[0], $par($run($time, ['c' => '64748B', 'sz' => 18])), $bg)
              . $cell($ws[1], $par($run($name, ['b' => true, 'c' => $ci >= 0 ? strtoupper(self::FG[$ci]) : '475569'])), $bg) . $cell($ws[2], $par($run($text)), $bg) . '</w:tr>';
          }
          $body .= '</w:tbl>'; break;
        case 'foot': $body .= $par($run($bl[1], ['c' => '94A3B8', 'sz' => 16]), '', '<w:spacing w:before="240"/>'); break;
      }
    }
    $ea = self::eaFont($lang);
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
      . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
      . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="' . $ea . '" w:cs="Arial"/><w:sz w:val="21"/><w:szCs w:val="21"/><w:lang w:val="' . $x($lang) . '" w:eastAsia="' . $x($lang) . '"/></w:rPr></w:rPrDefault>'
      . '<w:pPrDefault><w:pPr><w:spacing w:after="80" w:line="300" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
      . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:color w:val="0F172A"/></w:rPr></w:style>'
      . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="160"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr></w:style>'
      . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:pBdr><w:left w:val="single" w:sz="24" w:space="6" w:color="2563EB"/></w:pBdr><w:spacing w:before="320" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/><w:szCs w:val="26"/></w:rPr></w:style>'
      . '</w:styles>';
    $z = new ZipWriter();
    $z->add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
      . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
      . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
    $z->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
    $z->add('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->add('word/document.xml', $doc);
    $z->add('word/styles.xml', $styles);
    $z->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
      . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      . '<dc:title>' . $x($title) . '</dc:title><dc:creator>jt-vc-portal</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
    return $z->build();
  }

  // ------------------------------------------------------------------ ODF (.odt)
  private static function odt(array $blocks, string $title, string $lang): string {
    $x = [self::class, 'x'];
    [$lg, $ct] = array_pad(explode('-', $lang, 2), 2, '');
    $ea = str_starts_with($lang, 'ja') ? 'Noto Sans CJK JP' : 'Noto Sans CJK TC';
    $span = fn(string $t, string $st = '') => $st === '' ? $x($t) : '<text:span text:style-name="' . $st . '">' . $x($t) . '</text:span>';
    $p = fn(string $inner, string $st = 'Body') => '<text:p text:style-name="' . $st . '">' . $inner . '</text:p>';
    $body = ''; $tn = 0;
    $table = function (array $ratio, string $rowsXml, string $headXml = '') use (&$tn) {
      $tn++; $cols = '';
      foreach ($ratio as $i => $r) $cols .= '<table:table-column table:style-name="Tc' . $tn . '_' . $i . '"/>';
      return ['<table:table table:name="T' . $tn . '" table:style-name="Tbl">' . $cols . ($headXml !== '' ? '<table:table-header-rows>' . $headXml . '</table:table-header-rows>' : '') . $rowsXml . '</table:table>', $tn, $ratio];
    };
    $colStyles = '';
    $addTable = function (array $t) use (&$body, &$colStyles) {
      [$xml, $n, $ratio] = $t;
      foreach ($ratio as $i => $r) $colStyles .= '<style:style style:name="Tc' . $n . '_' . $i . '" style:family="table-column"><style:table-column-properties style:column-width="' . round(17 * $r, 2) . 'cm"/></style:style>';
      $body .= $xml;
    };
    $cell = fn(string $content, string $st = 'Cell') => '<table:table-cell table:style-name="' . $st . '" office:value-type="string">' . $content . '</table:table-cell>';
    foreach ($blocks as $bl) {
      switch ($bl[0]) {
        case 'title': $body .= '<text:h text:style-name="Title" text:outline-level="1">' . $x($bl[1]) . '</text:h>'; break;
        case 'meta':
          $rows = '';
          foreach ($bl[1] as [$k, $v]) $rows .= '<table:table-row>' . $cell($p($x($k), 'Muted'), 'CellMeta') . $cell($p($x($v)), 'CellMeta') . '</table:table-row>';
          $addTable($table([0.18, 0.82], $rows)); break;
        case 'h': $body .= '<text:h text:style-name="H" text:outline-level="2">' . $x($bl[1]) . '</text:h>'; break;
        case 'p': $body .= $p($x($bl[1])); break;
        case 'warn': $body .= $p($span($bl[1], 'Warn')); break;
        case 'item':
          [, $kind, $tag, $text, $meta, $cites] = $bl;
          $lead = $tag !== '' ? $span(' ' . $tag . ' ', $kind === 'd' ? 'TagD' : 'TagA') . ' ' : $span('• ', 'Dot');
          $body .= $p($lead . $x($text), 'Item');
          if ($meta !== '') $body .= $p($x($meta), 'Small');
          if ($cites !== '') $body .= $p($x($cites), 'Small');
          break;
        case 'table':
          [, $head, $rows, $ratio] = $bl;
          $h = '<table:table-row>'; foreach ($head as $c) $h .= $cell($p($span($c, 'B'), 'Muted'), 'CellHead'); $h .= '</table:table-row>';
          $r = ''; foreach ($rows as $row) { $r .= '<table:table-row>'; foreach ($row as $c) $r .= $cell($p($x((string)$c))); $r .= '</table:table-row>'; }
          $addTable($table($ratio, $r, $h)); break;
        case 'segs':
          $h = '<table:table-row>' . $cell($p($span(t('時間'), 'B'), 'Muted'), 'CellHead') . $cell($p($span(t('發言者'), 'B'), 'Muted'), 'CellHead') . $cell($p($span(t('內容'), 'B'), 'Muted'), 'CellHead') . '</table:table-row>';
          $r = '';
          foreach ($bl[1] as [$time, $name, $text, $ci]) {
            $cs = $ci >= 0 ? 'CellB' . $ci : 'Cell';
            $r .= '<table:table-row>' . $cell($p($x($time), 'Time'), $cs) . $cell($p($span($name, $ci >= 0 ? 'Spk' . $ci : 'B')), $cs) . $cell($p($x($text)), $cs) . '</table:table-row>';
          }
          $addTable($table([0.09, 0.16, 0.75], $r, $h)); break;
        case 'foot': $body .= $p($x($bl[1]), 'Foot'); break;
      }
    }
    $auto = $colStyles . '<style:style style:name="Tbl" style:family="table"><style:table-properties style:width="17cm" table:align="left" fo:margin-top="0.1cm" fo:margin-bottom="0.2cm"/></style:style>'
      . '<style:style style:name="Cell" style:family="table-cell"><style:table-cell-properties fo:padding="0.08cm" fo:border-bottom="0.5pt solid #e2e8f0"/></style:style>'
      . '<style:style style:name="CellHead" style:family="table-cell"><style:table-cell-properties fo:padding="0.08cm" fo:background-color="#f1f5f9" fo:border-bottom="0.5pt solid #e2e8f0"/></style:style>'
      . '<style:style style:name="CellMeta" style:family="table-cell"><style:table-cell-properties fo:padding="0.06cm" fo:background-color="#f1f5f9"/></style:style>';
    foreach (self::BG as $i => $bg) $auto .= '<style:style style:name="CellB' . $i . '" style:family="table-cell"><style:table-cell-properties fo:padding="0.08cm" fo:background-color="#' . $bg . '" fo:border-bottom="0.5pt solid #ffffff"/></style:style>';
    foreach (self::FG as $i => $fg) $auto .= '<style:style style:name="Spk' . $i . '" style:family="text"><style:text-properties fo:color="#' . $fg . '" fo:font-weight="bold" style:font-weight-asian="bold"/></style:style>';
    $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
      . 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" '
      . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" office:version="1.3"';
    $fonts = '<office:font-face-decls><style:font-face style:name="Sans" svg:font-family="\'Noto Sans\', Arial" style:font-family-generic="swiss"/><style:font-face style:name="CJK" svg:font-family="\'' . $ea . '\'" style:font-family-generic="swiss"/></office:font-face-decls>';
    $txt = fn(string $extra = '') => '<style:text-properties style:font-name="Sans" style:font-name-asian="CJK" fo:language="' . $x($lg) . '" fo:country="' . $x($ct ?: 'none') . '" style:language-asian="' . $x($lg) . '" style:country-asian="' . $x($ct ?: 'none') . '" ' . $extra . '/>';
    $ps = fn(string $name, string $pp, string $tp) => '<style:style style:name="' . $name . '" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties ' . $pp . '/><style:text-properties ' . $tp . '/></style:style>';
    $ts = fn(string $name, string $tp) => '<style:style style:name="' . $name . '" style:family="text"><style:text-properties ' . $tp . '/></style:style>';
    $bold = 'fo:font-weight="bold" style:font-weight-asian="bold"';
    $styles = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<office:document-styles ' . $ns . '>' . $fonts . '<office:styles>'
      . '<style:default-style style:family="paragraph">' . $txt('fo:font-size="10.5pt" style:font-size-asian="10.5pt" fo:color="#0f172a"') . '</style:default-style>'
      . '<style:style style:name="Standard" style:family="paragraph"><style:paragraph-properties fo:line-height="140%"/></style:style>'
      . $ps('Title', 'fo:margin-bottom="0.3cm"', 'fo:font-size="18pt" style:font-size-asian="18pt" ' . $bold)
      . $ps('H', 'fo:margin-top="0.5cm" fo:margin-bottom="0.2cm" fo:border-left="3pt solid #2563eb" fo:padding-left="0.2cm" fo:keep-with-next="always"', 'fo:font-size="13pt" style:font-size-asian="13pt" ' . $bold)
      . $ps('Body', 'fo:margin-bottom="0.15cm"', '')
      . $ps('Item', 'fo:margin-top="0.2cm"', '')
      . $ps('Small', 'fo:margin-left="0.4cm"', 'fo:font-size="8.5pt" style:font-size-asian="8.5pt" fo:color="#64748b"')
      . $ps('Muted', '', 'fo:color="#64748b"')
      . $ps('Time', '', 'fo:font-size="9pt" style:font-size-asian="9pt" fo:color="#64748b"')
      . $ps('Foot', 'fo:margin-top="0.5cm"', 'fo:font-size="8pt" style:font-size-asian="8pt" fo:color="#94a3b8"')
      . $ts('B', $bold) . $ts('Warn', 'fo:color="#b45309"') . $ts('Dot', 'fo:color="#94a3b8"')
      . $ts('TagD', $bold . ' fo:color="#15803d" fo:background-color="#dcfce7"') . $ts('TagA', $bold . ' fo:color="#1d4ed8" fo:background-color="#dbeafe"')
      . '</office:styles><office:automatic-styles><style:page-layout style:name="PL"><style:page-layout-properties fo:page-width="21cm" fo:page-height="29.7cm" fo:margin-top="2cm" fo:margin-bottom="2cm" fo:margin-left="2cm" fo:margin-right="2cm"/></style:page-layout></office:automatic-styles>'
      . '<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="PL"/></office:master-styles></office:document-styles>';
    $content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<office:document-content ' . $ns . '>' . $fonts . '<office:automatic-styles>' . $auto . '</office:automatic-styles><office:body><office:text>' . $body . '</office:text></office:body></office:document-content>';
    $meta = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<office:document-meta ' . $ns . '><office:meta><dc:title>' . $x($title) . '</dc:title><meta:generator>jt-vc-portal</meta:generator><meta:creation-date>' . date('Y-m-d\TH:i:s') . '</meta:creation-date></office:meta></office:document-meta>';
    $z = new ZipWriter();
    $z->add('mimetype', 'application/vnd.oasis.opendocument.text', true);
    $z->add('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
      . '<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
      . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
      . '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/></manifest:manifest>');
    $z->add('content.xml', $content);
    $z->add('styles.xml', $styles);
    $z->add('meta.xml', $meta);
    return $z->build();
  }
}
