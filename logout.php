<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/audit.php';

Auth::start();
if (Auth::check()) {
  Audit::log('logout', '');
}
Auth::logout();
header('Location: /jt-login');
exit;
