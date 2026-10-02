<?php
if (!defined('MAHINDO_MAIL')) { http_response_code(403); exit; }

/* ---------- helpers ---------- */
function mh_clean($s, $max = 500) {
    $s = trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string)$s)));
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}
function mh_text($s, $max = 3000) {
    $s = str_replace("\r", '', strip_tags((string)$s));
    return function_exists('mb_substr') ? mb_substr(trim($s), 0, $max, 'UTF-8') : substr(trim($s), 0, $max);
}
function mh_wants_json() {
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}
function mh_respond($ok, $redirect, $error = '') {
    if (mh_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    } else {
        $sep = strpos($redirect, '?') === false ? '?' : '&';
        $parts = explode('#', $redirect, 2);
        header('Location: ' . $parts[0] . ($ok ? '?sent=1' : '?sent=0') . (isset($parts[1]) ? '#' . $parts[1] : ''));
    }
    exit;
}
/* max 10 submissions / 10 min per IP, and at least 15 s between them.
   Split on purpose: mh_rate_check() only inspects, mh_rate_hit() records.
   Recording every attempt meant a validation error burnt the quota, so the
   immediate retry returned "Too many requests" instead of the real reason. */
define('MH_RATE_MAX', 10);
define('MH_RATE_WIN', 600);
define('MH_RATE_GAP', 15);

function mh_rate_file($tag) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
    return rtrim(sys_get_temp_dir(), '/\\') . '/mahindo_rl_' . $tag . '_' . md5($ip);
}
function mh_rate_hits($tag) {
    $now = time(); $hits = [];
    $file = mh_rate_file($tag);
    if (is_file($file)) { $d = json_decode((string)@file_get_contents($file), true); if (is_array($d)) $hits = $d; }
    return [$now, array_values(array_filter($hits, function ($t) use ($now) { return (int)$t > $now - MH_RATE_WIN; }))];
}
function mh_rate_check($tag) {
    list($now, $hits) = mh_rate_hits($tag);
    if (count($hits) >= MH_RATE_MAX) return true;
    if ($hits && $now - (int)end($hits) < MH_RATE_GAP) return true;
    return false;
}
function mh_rate_hit($tag) {
    list($now, $hits) = mh_rate_hits($tag);
    $hits[] = $now;
    @file_put_contents(mh_rate_file($tag), json_encode($hits), LOCK_EX);
}

/* ---------- minimal SMTP client (SSL 465 / STARTTLS 587) ---------- */
class SmtpMailer {
    private $cfg; private $acct; private $fp; public $error = ''; public $ref = ''; private $stage = '';

    public function __construct(array $cfg, array $acct) { $this->cfg = $cfg; $this->acct = $acct; }

    private function read() {
        $data = '';
        while (($line = fgets($this->fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    }
    private function expect(array $codes) {
        $r = $this->read();
        if (!in_array((int)substr($r, 0, 3), $codes, true)) throw new Exception('SMTP: ' . trim($r));
        return $r;
    }
    private function cmd($c, array $codes) { fwrite($this->fp, $c . "\r\n"); return $this->expect($codes); }
    private function enc($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }

    /** Retries transient SMTP failures (throttling / dropped connections).
        Never retries once DATA has been sent, so no duplicate mail. */
    public function send($subject, $text, $replyEmail = '', $replyName = '', array $attach = []) {
        $this->error = '';
        for ($i = 1; $i <= 3; $i++) {
            if ($this->sendOnce($subject, $text, $replyEmail, $replyName, $attach)) return true;
            if (!$this->isTransient() || $i === 3) break;
            sleep($i * 2);
        }
        $this->ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        error_log('Mahindo mail FAILED ref=' . $this->ref . ' stage=' . $this->stage . ' err=' . $this->error);
        return false;
    }

    /** A failure is worth retrying only if it happened before the body was sent. */
    private function isTransient() {
        if ($this->stage === 'final') return false;
        $e = $this->error;
        if (stripos($e, 'Connect failed') !== false) return true;
        if (stripos($e, 'timed out') !== false) return true;
        if (stripos($e, 'Broken pipe') !== false || stripos($e, 'Connection reset') !== false) return true;
        return (bool)preg_match('/SMTP:\s*(421|450|451|452)\b/', $e);
    }

    /** $attach = [['name'=>..., 'type'=>..., 'data'=>raw bytes], ...] */
    public function sendOnce($subject, $text, $replyEmail = '', $replyName = '', array $attach = []) {
        $this->stage = 'connect';
        try {
            $host = $this->cfg['host']; $port = (int)$this->cfg['port']; $sec = $this->cfg['secure'];
            $from = $this->acct['user']; $to = $this->acct['to'];
            $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
            $this->fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
            if (!$this->fp) throw new Exception('Connect failed: ' . $es);
            stream_set_timeout($this->fp, 20);
            $this->expect([220]);
            $me = isset($_SERVER['SERVER_NAME']) ? preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME']) : 'localhost';
            if ($me === '') $me = 'localhost';
            $this->cmd('EHLO ' . $me, [250]);
            if ($sec === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception('TLS failed');
                $this->cmd('EHLO ' . $me, [250]);
            }
            if ($sec !== 'none') {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($this->acct['user']), [334]);
                $this->cmd(base64_encode($this->acct['pass']), [235]);
            }
            $tos = array_values(array_filter(array_map('trim', explode(',', (string)$to)), 'strlen'));
            if (!$tos) throw new Exception('No recipient configured.');
            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
            $accepted = []; $rcptErr = '';
            foreach ($tos as $rcpt) {
                try { $this->cmd('RCPT TO:<' . $rcpt . '>', [250, 251]); $accepted[] = $rcpt; }
                catch (Exception $e) { $rcptErr = $e->getMessage(); error_log('Mahindo mail: recipient rejected ' . $rcpt); }
            }
            if (!$accepted) throw new Exception('All recipients were rejected. ' . $rcptErr);
            $this->cmd('DATA', [354]);
            $this->stage = 'final';   // body is going out now: never retry, it would duplicate

            $domain = substr(strrchr($from, '@'), 1);
            $b = 'mh_' . bin2hex(random_bytes(12));
            $h  = 'Date: ' . date('r') . "\r\n";
            $h .= 'From: ' . $this->enc($this->acct['from_name']) . ' <' . $from . ">\r\n";
            $h .= 'To: ' . implode(', ', array_map(function ($a) { return '<' . $a . '>'; }, $accepted)) . "\r\n";
            if ($replyEmail !== '') $h .= 'Reply-To: ' . ($replyName !== '' ? $this->enc($replyName) . ' ' : '') . '<' . $replyEmail . ">\r\n";
            $h .= 'Subject: ' . $this->enc($subject) . "\r\n";
            $h .= 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . ">\r\n";
            $h .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n";
            $body  = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($text), 76, "\r\n");
            foreach ($attach as $a) {
                $n = preg_replace('/[^A-Za-z0-9._-]/', '_', $a['name']);
                $body .= "--$b\r\nContent-Type: {$a['type']}; name=\"$n\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$n\"\r\n\r\n";
                $body .= chunk_split(base64_encode($a['data']), 76, "\r\n");
            }
            $body .= "--$b--\r\n";
            // everything is base64/ASCII-safe, so no dot-stuffing is needed
            fwrite($this->fp, $h . $body . "\r\n.\r\n");
            $this->expect([250]);
            @fwrite($this->fp, "QUIT\r\n"); @fclose($this->fp);
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            if (is_resource($this->fp)) @fclose($this->fp);
            return false;
        }
    }
}
