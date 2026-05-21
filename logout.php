<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/settings.php';

Auth::start();
if (Auth::check()) {
  Audit::log('logout', '');
}
Auth::logout();
header('Location: ' . Settings::loginUrl());
exit;
