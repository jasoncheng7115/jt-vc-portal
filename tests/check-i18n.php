<?php
/**
 * i18n 檢查閘門（發版前必跑，必須 0 問題）：
 *   1. 程式中出現的中文字串，必須是 t('…') / th('…') / I18n::t('…') 的第一個參數（字面常數）；
 *      否則視為「未翻譯的硬編碼中文」。註解不檢查。刻意保留的中文（如語言名稱）在同一行加 `i18n-ignore`。
 *   2. 每個 t() 鍵都必須在 lang/en/*.php 有非空英文翻譯，且英文翻譯不可含中文。
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

// 字典
$en = [];
foreach (glob($root . '/lang/en/*.php') ?: [] as $df) {
  $d = include $df;
  if (!is_array($d)) { $problems[] = "lang/en/" . basename($df) . " 未回傳 array"; continue; }
  foreach ($d as $k => $v) {
    if (isset($en[$k]) && $en[$k] !== $v) $problems[] = "lang/en 重複鍵且翻譯不同：{$k}";
    $en[$k] = $v;
  }
}
foreach ($keys as $k => $where) {
  if (!preg_match($cjk, $k)) continue;                                       // 純英文鍵不需翻譯
  if (!isset($en[$k]) || trim($en[$k]) === '') { $problems[] = "{$where[0]}  缺英文翻譯：{$k}"; continue; }
  if (preg_match($cjk, $en[$k])) $problems[] = "{$where[0]}  英文翻譯含中文：{$k}";
  preg_match_all('/\{[A-Za-z0-9_]+\}/', $k, $a); preg_match_all('/\{[A-Za-z0-9_]+\}/', $en[$k], $b);
  $a = array_unique($a[0]); $b = array_unique($b[0]); sort($a); sort($b);
  if ($a !== $b) $problems[] = "{$where[0]}  佔位不一致：{$k}";
}
$unused = array_diff(array_keys($en), array_keys($keys));

sort($problems);
foreach ($problems as $p) echo $p, "\n";
echo "\n", count($keys), " keys used, ", count($en), " en entries, ", count($unused), " unused, ", count($problems), " problems\n";
if ($unused && getenv('SHOW_UNUSED')) foreach ($unused as $u) echo "  unused: {$u}\n";
exit($problems ? 1 : 0);
