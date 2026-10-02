<?php
// Quote form (home #contact + each service page #enquire)  ->  sales@mahindo.in
define('MAHINDO_MAIL', 1);
$cfg = require __DIR__ . '/mail-config.php';
require __DIR__ . '/smtp-mailer.php';
$services = array('industrial-fabrication'=>'Industrial Fabrication Services','storage-tank-fabrication'=>'Storage Tank Fabrication','structural-fabrication'=>'Structural Fabrication Services','engineering-works'=>'Engineering Works','container-houses'=>'Container Houses','petroleum-tanker-fabrication'=>'Petroleum Tanker Fabrication','tanker-refurbishment'=>'Tanker Refurbishment','industrial-equipment-fabrication'=>'Industrial Equipment Fabrication');
$svc  = isset($_POST['service']) ? $_POST['service'] : '';
if (!isset($services[$svc])) $svc = '';                                // whitelist: only known service pages
$back = $svc ? 'services/' . $svc . '/index.html#enquire' : 'index.html#contact';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
if (!empty($_POST['website'])) mh_respond(true, $back);            // honeypot: pretend success
if (mh_rate_check('quote')) mh_respond(false, $back, 'Too many requests. Please try again in a few minutes.');

$name  = mh_clean(isset($_POST['name']) ? $_POST['name'] : '', 100);
$comp  = mh_clean(isset($_POST['company']) ? $_POST['company'] : '', 120);
$phone = mh_clean(isset($_POST['phone']) ? $_POST['phone'] : '', 25);
$email = mh_clean(isset($_POST['email']) ? $_POST['email'] : '', 150);
$req   = mh_text(isset($_POST['requirement']) ? $_POST['requirement'] : '', 1200);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
if ($name === '' || $phone === '' || $req === '') mh_respond(false, $back, 'Please fill in your name, phone and requirement.');

$text = "New quote request from the website\r\n\r\n"
      . ($svc ? "Service: " . $services[$svc] . "\r\n" : "")
      . "Name: $name\r\nCompany: " . ($comp ?: '-') . "\r\nPhone: $phone\r\nEmail: " . ($email ?: '-')
      . "\r\n\r\nRequirement:\r\n$req\r\n\r\n---\r\nSent from mahindo.in on " . date('d M Y, H:i') . " (IP " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-') . ")\r\n";

$m = new SmtpMailer($cfg, $cfg['accounts']['sales']);
$ok = $m->send('Quote Request - ' . ($svc ? $services[$svc] . ' - ' : '') . $name . ($comp ? ' (' . $comp . ')' : ''), $text, $email, $name);
mh_rate_hit('quote');
if (!$ok) error_log('Mahindo quote mail failed: ' . $m->error);
mh_respond($ok, $back, $ok ? '' : 'Sorry, that did not send. Please call or WhatsApp us and we will pick it up. Ref ' . $m->ref);
