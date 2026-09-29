<?php
/**
 * 會議記錄匯出 PDF / Word / ODT（v1.14.0）單元測試。項目 T44–T47 ↔ TEST_CHECKLIST 3.12 節。
 * 產出的檔案直接拆開檢查：ZIP 結構與 XML 合法、PDF 交叉索引正確、字型子集與 ToUnicode、改名套用、斷行。
 */
require __DIR__ . '/../bootstrap.php';
require_once '/app/lib/transcripts.php';
require_once '/app/lib/txexport.php';
require_once '/app/lib/requirements.php';

reset_data();
I18n::set('zh-TW');

function sample(): array {
  $segs = [
    ['seq' => 1, 'start_ms' => 1000, 'end_ms' => 4000, 'speaker' => 'S1', 'text' => '各位好，我們開始今天的會議。'],
    ['seq' => 2, 'start_ms' => 4500, 'end_ms' => 9000, 'speaker' => 'S2', 'text' => str_repeat('點數兌換模組目前進度百分之七十，金流廠商測試環境不穩定。', 12)],
    ['seq' => 3, 'start_ms' => 9500, 'end_ms' => 12000, 'speaker' => 'S1', 'text' => 'OK，那上線日期 2026-10-15 先維持。안녕하세요'],
  ];
  $tr = ['segments' => $segs, 'speaker_names' => ['S1' => '王經理', 'S2' => '陳工程師'], 'speaker_overrides' => ['3' => '林小姐'], 'language' => 'zh-Hant'];
  $sum = ['model' => 'test-model', 'summary' => ['text' => '討論點數兌換進度與上線日期。', 'grounded' => true],
    'items' => ['decisions' => [['text' => '上線日期先維持 15 號', 'citations' => [['start_ms' => 9500, 'speaker_id' => 'S1']]]],
                'actions' => [['text' => '廠商環境穩定後做串接測試', 'owner' => 'S1/S2', 'due_text' => '下週五', 'citations' => [['start_ms' => 4500, 'speaker_id' => 'S2']]]],
                'impacts' => [], 'risks' => [['text' => '金流環境不穩', 'citations' => []]], 'questions' => []],
    'chapters' => [['title' => '進度報告', 'start_ms' => 1000, 'end_ms' => 12000, 'percentage' => 100]],
    'speakers' => [['speaker_id' => 'S1', 'speaking_ms' => 5500, 'percentage' => 50, 'turn_count' => 2, 'chars' => 30, 'char_pct' => 40]]];
  $rec = ['room' => 'weekly-sync', 'mtime' => 1790000000, 'duration' => 12];
  $sess = ['owner_name' => '王經理', 'participants' => [['name' => '王經理'], ['name' => '陳工程師']]];
  return [$rec, $tr, $sum, $sess];
}

/** 拆 ZIP（只讀本產生器寫的格式）：[名稱 => [內容, 是否壓縮]]，順序照檔案內順序。 */
function unzip(string $z): array {
  $out = []; $p = 0;
  while (substr($z, $p, 4) === "PK\x03\x04") {
    $h = unpack('vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', $z, $p + 4);
    $name = substr($z, $p + 30, $h['nlen']);
    $raw = substr($z, $p + 30 + $h['nlen'] + $h['xlen'], $h['csize']);
    $data = $h['method'] === 8 ? gzinflate($raw) : $raw;
    ok(crc32($data) === $h['crc'], "CRC 不符：$name");
    ok(strlen($data) === $h['size'], "長度不符：$name");
    $out[$name] = [$data, $h['method'] === 8];
    $p += 30 + $h['nlen'] + $h['xlen'] + $h['csize'];
  }
  ok(substr($z, $p, 4) === "PK\x01\x02", '找不到中央目錄');
  ok(substr($z, -22, 4) === "PK\x05\x06", '找不到目錄結尾');
  eq(unpack('v', $z, strlen($z) - 12)[1], count($out), '目錄結尾的檔案數');
  return $out;
}
function wellFormed(string $xml, string $what): DOMDocument {
  $d = new DOMDocument();
  ok(@$d->loadXML($xml) === true, "$what 不是合法 XML");
  return $d;
}

test('T44 PDF：檔頭 / 交叉索引位址正確、內嵌字型子集、ToUnicode（可複製搜尋）、多頁', function () {
  [$rec, $tr, $sum, $sess] = sample();
  $tr['segments'] = array_merge(...array_fill(0, 12, $tr['segments']));   // 夠長才會跨頁
  $pdf = TxExport::render('pdf', TxExport::model($rec, $tr, $sum, $sess), '會議記錄：weekly-sync', 'zh-Hant');
  ok(str_starts_with($pdf, '%PDF-1.7'));
  ok(str_ends_with(rtrim($pdf), '%%EOF'));
  preg_match('/startxref\s+(\d+)/', $pdf, $m);
  ok(substr($pdf, (int)$m[1], 4) === 'xref', 'startxref 要指向 xref');
  preg_match('/xref\s+0 (\d+)\s+((?:\d{10} \d{5} [nf] \s*)+)/', $pdf, $x);
  $offs = array_slice(array_map(fn($l) => (int)substr($l, 0, 10), array_filter(explode("\n", trim($x[2])))), 1);
  foreach ($offs as $i => $o) ok(str_starts_with(substr($pdf, $o, 20), ($i + 1) . ' 0 obj'), '物件 ' . ($i + 1) . ' 的位址不對');
  ok(str_contains($pdf, '/FontFile2') && str_contains($pdf, '+NotoSansTC-Regular') && str_contains($pdf, '/ToUnicode'));
  ok(strlen($pdf) < 1024 * 1024, '只嵌入用到的字形，檔案應遠小於 7 MB 的原字型：' . strlen($pdf));
  preg_match('#/Count (\d+)#', $pdf, $c);
  ok((int)$c[1] >= 3, '內容夠長要自動分頁：' . $c[1]);
  // ToUnicode 對照表要含用到的字（例：「會」U+6703），複製出來才是原字
  preg_match_all('#stream\n(.*?)\nendstream#s', $pdf, $st);
  $cmap = ''; foreach ($st[1] as $s) { $u = @gzuncompress($s); if ($u !== false && str_contains($u, 'beginbfchar')) $cmap = $u; }
  ok(str_contains($cmap, '<6703>'), 'ToUnicode 缺「會」');
});

test('T44 PDF 斷行：中文逐字斷、不超出版面、行首不放標點、不產生空白行', function () {
  $pdf = new PdfWriter(new TtfFont(TxExport::FONT));
  $lines = $pdf->wrap(str_repeat('點數兌換模組目前進度百分之七十，金流廠商測試環境不穩定。', 5), 10.5, 200);
  ok(count($lines) > 5);
  foreach ($lines as $l) {
    ok($l !== '', '不可有空白行');
    ok($pdf->textWidth($l, 10.5) <= 200 + 10.5 * 1.01, '行寬超出：' . $l);
    ok(!preg_match('/^[，。、：；！？]/u', $l), '行首不可是標點：' . $l);
  }
  eq($pdf->wrap('hello world-wide-web', 10, 60)[0] !== 'hello world-wide-web', true, '英文長句要在字間斷行');
});

test('T45 Word（.docx）：ZIP 結構、各部分 XML 合法、改名 / 負責人 / 引用套用人名', function () {
  [$rec, $tr, $sum, $sess] = sample();
  $z = unzip(TxExport::render('docx', TxExport::model($rec, $tr, $sum, $sess), 'T', 'zh-Hant'));
  foreach (['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels', 'word/document.xml', 'word/styles.xml', 'docProps/core.xml'] as $n) {
    ok(isset($z[$n]), "缺 $n"); wellFormed($z[$n][0], $n);
  }
  $doc = $z['word/document.xml'][0];
  ok(str_contains($doc, '林小姐'), '單段改名要套用');
  ok(str_contains($doc, '負責：王經理/陳工程師'), '負責人代號要換成名字');
  ok(str_contains($doc, '出處：00:04 陳工程師'), '引用要換成名字');
  ok(str_contains($doc, '<w:tblHeader/>'), '逐字稿表格標題列要每頁重複');
  ok(str_contains($z['word/styles.xml'][0], 'Microsoft JhengHei'));
});

test('T45 ODT：mimetype 為第一個且不壓縮、各部分 XML 合法、日文會議用日文字型', function () {
  [$rec, $tr, $sum, $sess] = sample();
  $zip = TxExport::render('odt', TxExport::model($rec, $tr, $sum, $sess), 'T', 'ja');
  ok(substr($zip, 30, 8) === 'mimetype' && substr($zip, 38, 39) === 'application/vnd.oasis.opendocument.text', 'mimetype 必須第一個、不壓縮');
  $z = unzip($zip);
  eq(array_key_first($z), 'mimetype');
  eq($z['mimetype'][1], false);
  foreach (['META-INF/manifest.xml', 'content.xml', 'styles.xml', 'meta.xml'] as $n) { ok(isset($z[$n]), "缺 $n"); wellFormed($z[$n][0], $n); }
  ok(str_contains($z['content.xml'][0], '林小姐') && str_contains($z['content.xml'][0], '陳工程師'));
  ok(str_contains($z['content.xml'][0], 'Noto Sans CJK JP'));
});

test('T46 內容與語言：英文介面標籤與分隔用英文、XML 不允許的控制字元會被去掉', function () {
  [$rec, $tr, $sum, $sess] = sample();
  $tr['segments'][0]['text'] = "有\x01控制字元";
  I18n::set('en');
  $m = TxExport::model($rec, $tr, $sum, $sess);
  I18n::set('zh-TW');
  $meta = array_values(array_filter($m, fn($b) => $b[0] === 'meta'))[0][1];
  $flat = array_column($meta, 1, 0);
  eq($flat['Participant'] ?? $flat['Participants'] ?? null, '王經理, 陳工程師', '英文用 ", " 分隔');
  ok(str_starts_with($m[0][1], 'Meeting minutes: '), $m[0][1]);
  $z = unzip(TxExport::render('docx', $m, 'T', 'en'));
  wellFormed($z['word/document.xml'][0], 'document.xml');
  ok(!str_contains($z['word/document.xml'][0], "\x01"));
});

test('T47 執行環境檢查：映像內擴充與字型齊全；排程最後執行時間取自 worker 鎖檔', function () {
  eq(Requirements::missing(), []);
  eq(TxExport::missing(), []);
  @unlink(DATA_DIR . '/transcribe-worker.lock');
  eq(Requirements::workerLastRun(), 0, '沒跑過＝0');
  touch(DATA_DIR . '/transcribe-worker.lock', time() - 1200);
  ok(Requirements::workerLastRun() <= time() - 1200);
  touch(DATA_DIR . '/transcribe-worker.lock');
  ok(Requirements::workerLastRun() >= time() - 2);
});
