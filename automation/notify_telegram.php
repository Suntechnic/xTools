<?php
declare(strict_types=1);

/**
 * Скрипт для отправки уведомлений в Telegram через Bot API.
 *
 * Использование:
 *   php notify_telegram.php "Текст уведомления"
 *
 * Настройки берутся из файла tg.ini в той же директории.
 * Пример tg.ini:
 *
 * [telegram]
 * bot_token = "ВАШ_ТОКЕН_БОТА"
 * chat_id = "ВАШ_CHAT_ID"           ; или chat_ids = "CHAT1,CHAT2"
 * chat_ids = "CHAT1,CHAT2"          ; несколько чатов через запятую
 * proxy = "http://user:pass@host:port"
 */


/**
 * Возвращает длину строки с поддержкой UTF-8 при наличии mbstring.
 */
function getTextLength (string $Text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($Text, 'UTF-8');
    }

    return strlen($Text);
}

/**
 * Безопасно обрезает строку с поддержкой UTF-8 при наличии mbstring.
 */
function cutText (string $Text, int $Length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($Text, 0, $Length, 'UTF-8');
    }

    return substr($Text, 0, $Length);
}

/**
 * Возвращает значение из секции ini или из корня ini-файла.
 */
function getIniSetting (array $dctIni, string $Section, string $Key): ?string
{
    $Value = null;

    if (isset($dctIni[$Section]) && is_array($dctIni[$Section]) && isset($dctIni[$Section][$Key])) {
        $Value = $dctIni[$Section][$Key];
    }

    if ($Value === null && isset($dctIni[$Key])) {
        $Value = $dctIni[$Key];
    }

    if (!is_scalar($Value)) return null;

    $Value = trim((string)$Value);
    if ($Value === '') return null;

    return $Value;
}

/**
 * Извлекает HTTP-код из заголовков ответа PHP stream wrapper.
 */
function getHttpStatusCode (array $lstHeaders): int
{
    if (!$lstHeaders) return 0;

    $StatusLine = $lstHeaders[0] ?? '';
    if (!is_string($StatusLine)) return 0;

    if (preg_match('/\s(\d{3})\s/u', $StatusLine, $lstMatch)) {
        return (int)$lstMatch[1];
    }

    return 0;
}

/**
 * Отправляет текст в Telegram Bot API методом sendMessage.
 */
function sendTelegramMessage (string $Token, string $ChatId, string $Text, 
        ?string $Proxy = null,
        ?string $ProxyUser = null,
        ?string $ProxyPass = null
    ): array
{
    $Url = 'https://api.telegram.org/bot' . $Token . '/sendMessage';

    $dctPayload = [
        'chat_id' => $ChatId,
        'text' => $Text,
        'disable_web_page_preview' => true,
    ];

    $Headers = "Content-Type: application/x-www-form-urlencoded\r\n";

    $dctContext = [
        'http' => [
            'method' => 'POST',
            'content' => http_build_query($dctPayload),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ];

    if ($Proxy !== null) {
        $dctContext['http']['proxy'] = $Proxy;
        $dctContext['http']['request_fulluri'] = true;

        if ($ProxyUser !== null && $ProxyPass !== null) {
            $Auth = base64_encode($ProxyUser . ':' . $ProxyPass);
            $Headers .= "Proxy-Authorization: Basic {$Auth}\r\n";
        }
    }

    $dctContext['http']['header'] = $Headers;

    $context = stream_context_create($dctContext);
    $Response = @file_get_contents($Url, false, $context);

    /** @var array<int, string> $http_response_header */
    $lstHeaders = $http_response_header ?? [];
    $StatusCode = getHttpStatusCode($lstHeaders);

    if ($Response === false) {
        $dctError = error_get_last() ?: [];
        return [
            'ok' => false,
            'error' => 'Не удалось выполнить HTTP-запрос к Telegram API.',
            'http_status' => $StatusCode,
            'details' => $dctError['message'] ?? '',
        ];
    }

    $dctDecoded = json_decode($Response, true);
    if (!is_array($dctDecoded)) {
        return [
            'ok' => false,
            'error' => 'Telegram API вернул невалидный JSON.',
            'http_status' => $StatusCode,
            'details' => $Response,
        ];
    }

    if (($dctDecoded['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'Telegram API вернул ошибку.',
            'http_status' => $StatusCode,
            'details' => $dctDecoded['description'] ?? $Response,
        ];
    }

    return [
        'ok' => true,
        'http_status' => $StatusCode,
    ];
}

$IniPath = __DIR__ . '/tg.ini';

if (!is_file($IniPath)) {
    fwrite(STDERR, "Файл настроек не найден: {$IniPath}\n");
    exit(1);
}

$dctIni = parse_ini_file($IniPath, true, INI_SCANNER_RAW);
if (!is_array($dctIni)) {
    fwrite(STDERR, "Не удалось прочитать ini-файл: {$IniPath}\n");
    exit(1);
}

$Token = getIniSetting($dctIni, 'telegram', 'bot_token') ?? getIniSetting($dctIni, 'telegram', 'token');

$Proxy = getIniSetting($dctIni, 'telegram', 'proxy');
$ProxyUser = getIniSetting($dctIni, 'telegram', 'proxy_user');
$ProxyPass = getIniSetting($dctIni, 'telegram', 'proxy_pass');

$ChatIdsRaw = getIniSetting($dctIni, 'telegram', 'chat_ids') ?? getIniSetting($dctIni, 'telegram', 'chat_id');

if ($Token === null) {
    fwrite(STDERR, "В tg.ini не найден token/bot_token.\n");
    exit(1);
}

$ChatIds = [];
if ($ChatIdsRaw !== null) {
    $ChatIds = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/u', $ChatIdsRaw) ?: [])));
}

if ($ChatIds === []) {
    fwrite(STDERR, "В tg.ini не найден chat_id/chat_ids.\n");
    exit(1);
}

$CommitsText = $argv[1] ?? '';
$CommitsText = trim($CommitsText);
if ($CommitsText === '') {
    fwrite(STDOUT, "Список коммитов пустой, уведомление не отправлено.\n");
    exit(0);
}

$MessageText = $CommitsText;
$MaxLength = 4000;
if (getTextLength($MessageText) > $MaxLength) {
    $MessageText = cutText($MessageText, 3950) . "\n... (сообщение обрезано)";
}

$SendErrors = [];
foreach ($ChatIds as $ChatId) {
    $dctResult = sendTelegramMessage($Token, $ChatId, $MessageText, $Proxy, $ProxyUser, $ProxyPass);
    if (($dctResult['ok'] ?? false) !== true) {
        $Error = (string)($dctResult['error'] ?? 'Неизвестная ошибка.');
        $Details = (string)($dctResult['details'] ?? '');
        $StatusCode = (int)($dctResult['http_status'] ?? 0);

        $SendErrors[] = "chat_id {$ChatId}: {$Error}";
        if ($StatusCode > 0) $SendErrors[] = "HTTP status: {$StatusCode}";
        if ($Details !== '') $SendErrors[] = "Детали: {$Details}";
    }
}

if ($SendErrors !== []) {
    foreach ($SendErrors as $ErrorLine) {
        fwrite(STDERR, $ErrorLine . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "Уведомление в Telegram успешно отправлено в " . count($ChatIds) . " чат(а/ов).\n");
exit(0);