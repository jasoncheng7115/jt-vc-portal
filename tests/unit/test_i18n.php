<?php
require __DIR__ . '/../bootstrap.php';

test('normalize：zh* → zh-TW、en* → en、其他 → null', function () {
  eq(I18n::normalize('zh-TW'), 'zh-TW');
  eq(I18n::normalize('zh_CN'), 'zh-TW');
  eq(I18n::normalize('ZH'), 'zh-TW');
  eq(I18n::normalize('en-US'), 'en');
  eq(I18n::normalize('ja'), null);
  eq(I18n::normalize(''), null);
});

test('Accept-Language 依 q 值排序，略過不支援語言', function () {
  eq(I18n::fromAcceptLanguage('ja,en;q=0.8,zh-TW;q=0.9'), 'zh-TW');
  eq(I18n::fromAcceptLanguage('en-GB,en;q=0.9,zh;q=0.1'), 'en');
  eq(I18n::fromAcceptLanguage('fr,de'), null);
  eq(I18n::fromAcceptLanguage('zh;q=0,en;q=0.5'), 'en');
  eq(I18n::fromAcceptLanguage(''), null);
});

test('t()：zh-TW 回原文並套變數；en 查字典；缺字退回原文', function () {
  eq(I18n::t('已寄至 {to}。', ['to' => 'a@b'], 'zh-TW'), '已寄至 a@b。');
  eq(I18n::t('儀表板', [], 'en'), 'Dashboard');
  eq(I18n::t('這個鍵不存在 {x}', ['x' => 1], 'en'), '這個鍵不存在 1');
});

test('英文字典每一筆都非空且不含中文、佔位一致', function () {
  $d = I18n::dict('en');
  ok(count($d) > 100, '字典筆數 ' . count($d));
  foreach ($d as $k => $v) {
    ok(is_string($v) && trim($v) !== '', "空翻譯：$k");
    ok(!preg_match('/[\x{4e00}-\x{9fff}]/u', $v), "英文含中文：$k");
    preg_match_all('/\{[A-Za-z0-9_]+\}/', $k, $a); preg_match_all('/\{[A-Za-z0-9_]+\}/', $v, $b);
    $a = array_unique($a[0]); $b = array_unique($b[0]); sort($a); sort($b);
    ok($a === $b, "佔位不一致：$k");
  }
});

test('Mailer 預設範本：空白 / 任一語言預設 → 依語言套用；自訂保留', function () {
  require_once '/app/lib/mailer.php';
  ok(Mailer::isDefaultTpl('', 'subject'));
  ok(Mailer::isDefaultTpl(Mailer::DEFAULT_SUBJECT_TPL, 'subject'));
  ok(Mailer::isDefaultTpl(I18n::t(Mailer::DEFAULT_BODY_TPL, [], 'en'), 'body'));
  ok(!Mailer::isDefaultTpl('My custom {room}', 'subject'));
  I18n::set('en');
  ok(strpos(Mailer::defaultSubject(), '{room}') !== false && !preg_match('/[\x{4e00}-\x{9fff}]/u', Mailer::defaultSubject()));
  I18n::set('zh-TW');
  eq(Mailer::defaultSubject(), Mailer::DEFAULT_SUBJECT_TPL);
});

test('會議語言：ui 依介面語言；指定碼原樣', function () {
  require_once '/app/lib/settings.php';
  Settings::setMeetingLang('ui');
  I18n::set('en');    eq(Settings::getMeetingLang(), 'en');
  I18n::set('zh-TW'); eq(Settings::getMeetingLang(), 'zh-TW');
  Settings::setMeetingLang('ja');
  eq(Settings::getMeetingLang(), 'ja');
  Settings::setMeetingLang('xx');
  eq(Settings::getMeetingLangSetting(), 'ui');
});

summary();
