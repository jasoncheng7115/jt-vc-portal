<?php
/**
 * 登入狀態探測（GET /session-check → {"login":true|false}）。
 * 頁面上的操作（播放錄影、存發言者名字…）失敗時，用來分辨「登入逾時」與其他原因，好給使用者清楚的訊息。
 * 受保護端點未登入一律回 404（不暴露入口），前端無從分辨，所以另開這支；只回一個布林值，不帶任何其他資訊。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['login' => Auth::user() !== null]);
