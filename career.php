<?php
// Career form (career.html)  ->  hr@mahindo.in, resume attached
define('MAHINDO_MAIL', 1);
$cfg = require __DIR__ . '/mail-config.php';
require __DIR__ . '/smtp-mailer.php';
$back = 'career.html#apply';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
if (!empty($_POST['website'])) mh_respond(true, $back);
if (mh_rate_check('career')) mh_respond(false, $back, 'Too many requests. Please try again in a few minutes.');

$f = [];
foreach (['name' => 100, 'email' => 150, 'phone' => 25, 'city' => 80, 'discipline' => 80, 'experience' => 40,
          'position' => 80, 'salary' => 60, 'skills' => 250] as $k => $max) {
    $f[$k] = mh_clean(isset($_POST[$k]) ? $_POST[$k] : '', $max);
}
$summary = mh_text(isset($_POST['summary']) ? $_POST['summary'] : '', 1500);
foreach (['name', 'email', 'phone', 'city', 'discipline', 'experience'] as $k) {
    if ($f[$k] === '') mh_respond(false, $back, 'Please fill in all required fields.');
}
if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) mh_respond(false, $back, 'Please enter a valid email address.');
if ($summary === '') mh_respond(false, $back, 'Please add a short professional summary.');

// resume: PDF / DOC / DOCX, max 5 MB
if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) mh_respond(false, $back, 'Please upload your resume (PDF, DOC or DOCX, max 5 MB).');
$file = $_FILES['resume'];
if ($file['size'] > 5 * 1024 * 1024) mh_respond(false, $back, 'Your resume is larger than 5 MB.');
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
          'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
if (!isset($types[$ext])) mh_respond(false, $back, 'Resume must be a PDF, DOC or DOCX file.');
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $file['tmp_name']); finfo_close($fi);
    $allowed = ['application/pdf', 'application/msword', 'application/zip', 'application/CDFV2', 'application/x-ole-storage',
                'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    if (!in_array($mime, $allowed, true)) mh_respond(false, $back, 'Resume must be a PDF, DOC or DOCX file.');
}
$data = file_get_contents($file['tmp_name']);

$text = "New job application from the website\r\n\r\n"
      . "Name: {$f['name']}\r\nEmail: {$f['email']}\r\nMobile: {$f['phone']}\r\nCity: {$f['city']}\r\n"
      . "Discipline: {$f['discipline']}\r\nExperience: {$f['experience']}\r\nPosition: " . ($f['position'] ?: '-') . "\r\n"
      . "Expected salary: " . ($f['salary'] ?: '-') . "\r\nKey skills: " . ($f['skills'] ?: '-')
      . "\r\n\r\nProfessional summary:\r\n$summary\r\n\r\nResume attached.\r\n---\r\nSent from mahindo.in/career on " . date('d M Y, H:i') . "\r\n";

$m = new SmtpMailer($cfg, $cfg['accounts']['hr']);
$ok = $m->send('Job Application' . ($f['position'] !== '' ? ' - ' . $f['position'] : '') . ' - ' . $f['name'], $text, $f['email'], $f['name'],
               [['name' => 'Resume_' . $f['name'] . '.' . $ext, 'type' => $types[$ext], 'data' => $data]]);
mh_rate_hit('career');
if (!$ok) error_log('Mahindo career mail failed: ' . $m->error);
mh_respond($ok, $back, $ok ? '' : 'Sorry, that did not send. Please call or WhatsApp us and we will pick it up. Ref ' . $m->ref);
