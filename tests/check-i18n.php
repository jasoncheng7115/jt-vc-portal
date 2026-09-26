<?php
/**
 * i18n 檢查閘門（發版前必跑，必須 0 問題）：
 *   1. 程式中出現的中文字串，必須是 t('…') / th('…') / I18n::t('…') 的第一個參數（字面常數）；
 *      否則視為「未翻譯的硬編碼中文」。註解不檢查。刻意保留的中文（如語言名稱）在同一行加 `i18n-ignore`。
 *   2. 每個 t() 鍵都必須在每種語言 lang/<code>/*.php（en、ja…）有非空翻譯；英文不可含中文、其他語言不可與中文原文完全相同。
 *   3. t() 的鍵必須是字面常數（不可用變數或 "…$x…" 內插）；動態內容用 {name} 佔位。
 *   4. 翻譯中的 {佔位} 必須與原文一致。
 * 用法（容器內）：php tests/check-i18n.php /app   → 印出問題清單，exit 1 表示有問題。
 */
$root = rtrim($argv[1] ?? __DIR__ . '/../app', '/');
$cjk = '/[\x{3400}-\x{9fff}\x{f900}-\x{faff}\x{3000}-\x{303f}\x{ff00}-\x{ffef}]/u';

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$problems = [];
$keys = [];   // key => [file:line, ...]

function str_value(string $tok): ?string {
  $q = $tok[0];
  if ($q === "'") return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($tok, 1, -1));
  if ($q === '"') {
    if (preg_match('/(?<!\\\\)\$[A-Za-z_{]/', $tok)) return null;          // 有內插
    return stripcslashes(substr($tok, 1, -1));
  }
  return null;
}

foreach ($files as $f) {
  $path = $f->getPathname();
  if (substr($path, -4) !== '.php') continue;
  $rel = substr($path, strlen($root) + 1);
  if (strpos($rel, 'lang/') === 0) continue;                                 // 字典本身
  $src = file_get_contents($path);
  $lines = explode("\n", $src);
  $toks = token_get_all($src);
  $n = count($toks);
  // 找出「非空白 / 非註解」的前一個 token，判斷字串是否為 t( 的第一個參數
  $sig = [];
  for ($i = 0; $i < $n; $i++) {
    $t = $toks[$i];
    if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
    $sig[] = $i;
  }
  $pos = array_flip($sig);
  foreach ($sig as $k => $i) {
    $t = $toks[$i];
    if (!is_array($t)) continue;
    [$id, $text, $line] = $t;
    if (!in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) continue;
    // 是否為 t( / th( / I18n::t( 的第一參數
    $isKey = false;
    if ($id === T_CONSTANT_ENCAPSED_STRING && $k >= 2) {
      $p1 = $toks[$sig[$k - 1]]; $p2 = $toks[$sig[$k - 2]];
      if ($p1 === '(' && is_array($p2) && $p2[0] === T_STRING && in_array($p2[1], ['t', 'th'], true)) $isKey = true;
    }
    if ($isKey) {
      $v = str_value($text);
      if ($v === null) { $problems[] = "{$rel}:{$line}  t() 鍵不可含變數內插：{$text}"; continue; }
      $keys[$v][] = "{$rel}:{$line}";
      continue;
    }
    // t() 內用了非字面鍵（變數）→ 由呼叫處上一 token 判斷
    if (!preg_match($cjk, $text)) continue;
    $lineText = $lines[$line - 1] ?? '';
    // 多行 token：任何一行有 i18n-ignore 即略過
    $span = substr_count($text, "\n");
    $ignored = false;
    for ($L = $line; $L <= $line + $span; $L++) {
      if (strpos($lines[$L - 1] ?? '', 'i18n-ignore') !== false) { $ignored = true; break; }
    }
    if ($ignored) continue;
    // 只回報含中文的片段（取前 60 字）
    preg_match_all('/[^\s<>"\'=]*' . substr($cjk, 1, -2) . '[^<>"\']*/u', $text, $mm);
    $frag = trim(implode(' | ', array_slice($mm[0], 0, 3)));
    $problems[] = "{$rel}:{$line}  未翻譯：" . mb_substr($frag !== '' ? $frag : trim($text), 0, 80);
  }
  // t($var) 這種非字面鍵
  foreach ($sig as $k => $i) {
    $t = $toks[$i];
    if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], ['t', 'th'], true)) continue;
    $prev = $k > 0 ? $toks[$sig[$k - 1]] : null;
    if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) continue;
    if (($toks[$sig[$k + 1] ?? -1] ?? null) !== '(') continue;
    $a = $toks[$sig[$k + 2] ?? -1] ?? null;
    if (!is_array($a) || $a[0] !== T_CONSTANT_ENCAPSED_STRING) {
      $problems[] = "{$rel}:{$t[2]}  t() 的鍵必須是字面字串（勿傳變數）";
    }
  }
}

// 字典：lang/<code>/*.php（en、ja…）每種語言都要完整
$langs = array_values(array_filter(array_map('basename', glob($root . '/lang/*', GLOB_ONLYDIR) ?: [])));
$dicts = [];
foreach ($langs as $lc) {
  $dicts[$lc] = [];
  foreach (glob($root . "/lang/{$lc}/*.php") ?: [] as $df) {
    $d = include $df;
    if (!is_array($d)) { $problems[] = "lang/{$lc}/" . basename($df) . " 未回傳 array"; continue; }
    foreach ($d as $k => $v) {
      if (isset($dicts[$lc][$k]) && $dicts[$lc][$k] !== $v) $problems[] = "lang/{$lc} 重複鍵且翻譯不同：{$k}";
      $dicts[$lc][$k] = $v;
    }
  }
}
$en = $dicts['en'] ?? [];
foreach ($keys as $k => $where) {
  if (!preg_match($cjk, $k)) continue;                                       // 純英文鍵不需翻譯
  preg_match_all('/\{[A-Za-z0-9_]+\}/', $k, $a); $a = array_unique($a[0]); sort($a);
  foreach ($dicts as $lc => $d) {
    if (!isset($d[$k]) || trim($d[$k]) === '') { $problems[] = "{$where[0]}  缺 {$lc} 翻譯：{$k}"; continue; }
    if ($lc === 'en' && preg_match($cjk, $d[$k])) $problems[] = "{$where[0]}  英文翻譯含中文：{$k}";
    // 非英文語言與中文原文相同時，若含繁中專用字（日文不用的字形）才視為未翻譯；否則（如「開放時間」「{n} 秒」）屬合理相同
    if ($lc !== 'en' && $d[$k] === $k && preg_match('/[會錄帳號們這與儀刪頁輸擇關顯參讓幾實]/u', $k)) $problems[] = "{$where[0]}  {$lc} 翻譯仍是繁中原文（未翻譯）：{$k}";
    if ($lc === 'ja' && preg_match('/[會錄帳號們這與儀刪頁輸擇關顯參讓幾實]/u', $d[$k])) $problems[] = "{$where[0]}  ja 翻譯含繁中專用字：{$k}";
    preg_match_all('/\{[A-Za-z0-9_]+\}/', $d[$k], $b); $b = array_unique($b[0]); sort($b);
    if ($a !== $b) $problems[] = "{$where[0]}  {$lc} 佔位不一致：{$k}";
  }
}
$unused = array_diff(array_keys($en), array_keys($keys));

sort($problems);
foreach ($problems as $p) echo $p, "\n";
echo "\n", count($keys), " keys used, ", implode(', ', array_map(fn($l) => count($dicts[$l]) . " {$l}", array_keys($dicts))), " entries, ", count($unused), " unused, ", count($problems), " problems\n";
if ($unused && getenv('SHOW_UNUSED')) foreach ($unused as $u) echo "  unused: {$u}\n";
exit($problems ? 1 : 0);
