<?php

declare(strict_types=1);

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
function sendTelegramMessage (string $Token, string $ChatId, string $Text): array
{
    $Url = 'https://api.telegram.org/bot' . $Token . '/sendMessage';

    $dctPayload = [
        'chat_id' => $ChatId,
        'text' => $Text,
        'disable_web_page_preview' => true,
    ];

    $dctContext = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($dctPayload),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ];

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
$ChatId = getIniSetting($dctIni, 'telegram', 'chat_id');

if ($Token === null) {
    fwrite(STDERR, "В tg.ini не найден token/bot_token.\n");
    exit(1);
}

if ($ChatId === null) {
    fwrite(STDERR, "В tg.ini не найден chat_id.\n");
    exit(1);
}

$CommitsText = $argv[1] ?? '';
$CommitsText = trim($CommitsText);
if ($CommitsText === '') {
    fwrite(STDOUT, "Список коммитов пустой, уведомление не отправлено.\n");
    exit(0);
}

$MessageText = "Новые коммиты:\n" . $CommitsText;
$MaxLength = 4000;
if (getTextLength($MessageText) > $MaxLength) {
    $MessageText = cutText($MessageText, 3950) . "\n... (сообщение обрезано)";
}

$dctResult = sendTelegramMessage($Token, $ChatId, $MessageText);
if (($dctResult['ok'] ?? false) !== true) {
    $Error = (string)($dctResult['error'] ?? 'Неизвестная ошибка.');
    $Details = (string)($dctResult['details'] ?? '');
    $StatusCode = (int)($dctResult['http_status'] ?? 0);

    fwrite(STDERR, "Ошибка отправки в Telegram: {$Error}\n");
    if ($StatusCode > 0) fwrite(STDERR, "HTTP status: {$StatusCode}\n");
    if ($Details !== '') fwrite(STDERR, "Детали: {$Details}\n");
    exit(1);
}

fwrite(STDOUT, "Уведомление в Telegram успешно отправлено.\n");
exit(0);
