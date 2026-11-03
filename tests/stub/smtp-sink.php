<?php
// 測試用 SMTP 收信器（不加密、不驗證）：每封信存成 /out/<序號>.eml。只給整合測試用。
// 用法：php smtp-sink.php <port> <輸出目錄>
$port = (int)($argv[1] ?? 2525); $out = $argv[2] ?? '/out';
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $err) or die("listen failed: $err\n");
$n = 0;
while ($c = @stream_socket_accept($srv, -1)) {
  fwrite($c, "220 sink ESMTP\r\n");
  $data = null;
  while (($line = fgets($c)) !== false) {
    if ($data !== null) {
      if (rtrim($line, "\r\n") === '.') { file_put_contents(sprintf('%s/%03d.eml', $out, ++$n), $data); $data = null; fwrite($c, "250 queued\r\n"); }
      else $data .= $line;
      continue;
    }
    $cmd = strtoupper(substr(trim($line), 0, 4));
    if ($cmd === 'EHLO' || $cmd === 'HELO') fwrite($c, "250 sink\r\n");
    elseif ($cmd === 'DATA') { $data = ''; fwrite($c, "354 go\r\n"); }
    elseif ($cmd === 'RCPT' && stripos($line, 'reject@') !== false) fwrite($c, "550 5.1.1 <reject@example.com>: user unknown\r\n");   // 模擬收件伺服器拒收（T91）
    elseif ($cmd === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
    else fwrite($c, "250 ok\r\n");
  }
  fclose($c);
}
