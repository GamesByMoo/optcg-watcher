<?php
declare(strict_types=1);

/**
 * Sends an HTML mail with PHP's mail(), which works on STRATO webhosting
 * as long as MAIL_FROM is an address of your own domain.
 */
function send_mail(string $to, string $subject, string $html): bool
{
    $recipients = array_filter(array_map('trim', preg_split('/[,;]/', $to)));
    if (!$recipients) {
        return false;
    }
    $headers = [
        'From: OPTCG Preorder Watcher <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: OPTCG-Watcher',
    ];
    $body = chunk_split(base64_encode($html));
    $subj = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $ok = true;
    foreach ($recipients as $rcpt) {
        if (!filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        // -f sets the envelope sender, required by many hosters for delivery
        $ok = mail($rcpt, $subj, $body, implode("\r\n", $headers), '-f' . MAIL_FROM) && $ok;
    }
    return $ok;
}

function alert_mail_html(array $alerts): string
{
    $rows = '';
    foreach ($alerts as $a) {
        $old = $a['old_price'] !== null ? '<br><small style="color:#888">previously alerted at ' . h(eur($a['old_price'])) . '</small>' : '';
        $rows .= '<tr>'
            . '<td style="padding:8px;border-bottom:1px solid #eee"><a href="' . h($a['url']) . '">' . h($a['title']) . '</a>'
            . '<br><small style="color:#666">' . h($a['shop']) . ' (' . h($a['country']) . ') · ' . h($a['status']) . ' · rule: ' . h($a['rule']) . '</small></td>'
            . '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap"><b>' . h(eur($a['price'])) . '</b>'
            . '<br><small style="color:#666">limit ' . h(eur($a['max_price'])) . '</small>' . $old . '</td>'
            . '</tr>';
    }
    return '<div style="font-family:Arial,sans-serif;max-width:640px">'
        . '<h2 style="margin:0 0 12px">' . count($alerts) . ' One Piece TCG offer(s) below your limit</h2>'
        . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
        . '<p style="color:#888;font-size:12px">Sent by your OPTCG Preorder Watcher.</p></div>';
}
