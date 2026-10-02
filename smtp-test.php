<?php
// ONE-TIME SMTP DIAGNOSTIC. Open:  https://mahindo.in/smtp-test.php?key=YOUR_TEST_KEY
// Then DELETE THIS FILE from the server.
define('MAHINDO_MAIL', 1);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
$cfg = require __DIR__ . '/mail-config.php';
require __DIR__ . '/smtp-mailer.php';

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (strlen($cfg['test_key']) < 12) { http_response_code(403); exit("Set TEST_KEY (12+ characters) in your .env file first.\n"); }
if (!hash_equals($cfg['test_key'], $key)) { http_response_code(403); exit("Forbidden\n"); }

echo "Mahindo SMTP test - " . date('d M Y H:i:s') . "\n";
echo "PHP " . PHP_VERSION . " | host {$cfg['host']}:{$cfg['port']} ({$cfg['secure']})\n";
foreach (['openssl', 'mbstring', 'fileinfo'] as $ext) echo str_pad("ext $ext", 14) . (extension_loaded($ext) ? "yes\n" : "MISSING" . ($ext === 'openssl' ? " (required)\n" : "\n"));
echo str_pad("random_bytes", 14) . (function_exists('random_bytes') ? "yes\n" : "MISSING (needs PHP 7+)\n");
echo "\n";

$allOk = true;
foreach ($cfg['accounts'] as $name => $acct) {
    echo "[$name] {$acct['user']} -> {$acct['to']}\n";
    if (strpos($acct['pass'], 'PUT_') === 0) { echo "  FAIL  password not set in .env\n\n"; $allOk = false; continue; }
    $m = new SmtpMailer($cfg, $acct);
    $ok = $m->send("SMTP test ($name) " . date('H:i:s'), "This is a test email from mahindo.in ($name account).\r\nIf you can read this, SMTP works.\r\n", '', '');
    if ($ok) echo "  OK    test email sent - check the {$acct['to']} inbox (and spam)\n\n";
    else {
        $allOk = false; echo "  FAIL  {$m->error}\n";
        if (stripos($m->error, '535') !== false) echo "        -> wrong mailbox password, or mailbox does not exist in hPanel > Emails\n";
        if (stripos($m->error, 'Connect failed') !== false) echo "        -> port blocked: set port 587 + secure 'tls' in mail-config.php and retry\n";
        if (stripos($m->error, 'TLS') !== false) echo "        -> try port 465 + secure 'ssl'\n";
        echo "\n";
    }
}
echo $allOk ? "ALL OK. Now DELETE smtp-test.php from the server.\n" : "Fix the FAIL lines above and reload this page.\n";
