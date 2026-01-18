<?php
// contact.php — простой SMTP-отправитель для формы.
// Использует прямое соединение по SSL (465) с AUTH LOGIN.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$envFile = __DIR__ . '/.env.local';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if ($key !== '' && !isset($_ENV[$key])) {
            $_ENV[$key] = $val;
        }
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$message = trim($input['message'] ?? '');

if ($name === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Заполните имя и телефон']);
    exit;
}

// Убираем переводы строк, чтобы не было инъекций в заголовки.
$sanitize = static function (string $value): string {
    return preg_replace('/[\r\n]+/', ' ', $value);
};

$name = $sanitize($name);
$phone = $sanitize($phone);
$message = $sanitize($message);

$config = [
    'host' => $_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?: 'gymnastic.ballsrepair.ru',
    'port' => (int)($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?: 465),
    'secure' => ($_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?: 'true') !== 'false',
    'user' => $_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?: 'info@gymnastic.ballsrepair.ru',
    'pass' => $_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?: '',
    'from' => $_ENV['SMTP_FROM'] ?? getenv('SMTP_FROM') ?: 'Ball Gymnastic <info@gymnastic.ballsrepair.ru>',
    'to'   => $_ENV['SMTP_TO'] ?? getenv('SMTP_TO') ?: 'ballsrepair@mail.ru',
    'fallback_mail' => ($_ENV['SMTP_FALLBACK_MAIL'] ?? getenv('SMTP_FALLBACK_MAIL') ?: 'true') !== 'false',
];

if ($config['pass'] === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Не задан SMTP пароль (SMTP_PASS)']);
    exit;
}

$subject = 'Заявка на ремонт мяча';
$body = "Имя: {$name}\nТелефон: {$phone}\nКомментарий: {$message}";

try {
    smtp_send($config, $subject, $body);
    echo json_encode(['ok' => true, 'via' => 'smtp']);
} catch (Throwable $e) {
    log_error($e->getMessage());
    if ($config['fallback_mail']) {
        try {
            mail_send($config, $subject, $body);
            echo json_encode(['ok' => true, 'via' => 'mail()', 'warning' => $e->getMessage()]);
            exit;
        } catch (Throwable $mailErr) {
            log_error('Fallback mail() failed: ' . $mailErr->getMessage());
        }
    }
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось отправить письмо', 'detail' => $e->getMessage()]);
}

function smtp_send(array $cfg, string $subject, string $body): void
{
    $protocol = $cfg['secure'] ? 'ssl://' : '';
    $conn = @fsockopen($protocol . $cfg['host'], $cfg['port'], $errno, $errstr, 15);
    if (!$conn) {
        throw new RuntimeException("SMTP connect error: {$errstr} ({$errno})");
    }
    stream_set_timeout($conn, 15);

    $read = static function ($conn): string {
        $data = '';
        while ($str = fgets($conn, 515)) {
            $data .= $str;
            if (strlen($str) < 4 || $str[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $expect = static function ($conn, $read, string $cmd, array $okCodes) {
        fwrite($conn, $cmd . "\r\n");
        $resp = $read($conn);
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException("SMTP error for '{$cmd}': {$resp}");
        }
        return $resp;
    };

    $read($conn); // greeting
    $hostname = gethostname() ?: 'localhost';
    $expect($conn, $read, "EHLO {$hostname}", [250]);
    $expect($conn, $read, "AUTH LOGIN", [334]);
    $expect($conn, $read, base64_encode($cfg['user']), [334]);
    $expect($conn, $read, base64_encode($cfg['pass']), [235]);
    $expect($conn, $read, "MAIL FROM:<{$cfg['user']}>", [250]);
    $expect($conn, $read, "RCPT TO:<{$cfg['to']}>", [250, 251]);
    $expect($conn, $read, "DATA", [354]);

    $headers = [
        'From' => $cfg['from'],
        'To' => $cfg['to'],
        'Subject' => $subject,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'Date' => date(DATE_RFC2822),
    ];

    $headerLines = [];
    foreach ($headers as $key => $val) {
        $headerLines[] = "{$key}: {$val}";
    }

    $data = implode("\r\n", $headerLines) . "\r\n\r\n" . $body . "\r\n.\r\n";
    $expect($conn, $read, rtrim($data), [250]);
    $expect($conn, $read, "QUIT", [221]);
    fclose($conn);
}

function mail_send(array $cfg, string $subject, string $body): void
{
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'From' => $cfg['from'],
        'Reply-To' => $cfg['from'],
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];
    $headerLines = [];
    foreach ($headers as $key => $val) {
        $headerLines[] = "{$key}: {$val}";
    }
    $additional = '-f' . ($cfg['user'] ?: 'info@gymnastic.ballsrepair.ru');
    $ok = mail($cfg['to'], $encodedSubject, $body, implode("\r\n", $headerLines), $additional);
    if (!$ok) {
        throw new RuntimeException('mail() did not return true');
    }
}

function log_error(string $message): void
{
    $logFile = __DIR__ . '/contact-error.log';
    $timestamp = date('c');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    error_log("contact.php: {$message}");
}
