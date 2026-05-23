#!/usr/bin/env php
<?php

/**
 * Сжимает файлы изображений в ./upload для dev-сайтов на Bitrix.
 *
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Запускайте только из CLI.\n");
    exit(1);
}

const DEFAULT_QUALITY = 35;
const DEFAULT_MAX_WIDTH = 320;
const DEFAULT_MAX_HEIGHT = 320;
const DEFAULT_LOG_FILE = 'compress-upload-success.log';
const DEFAULT_HTACCESS_MARKER = 'SetEnv APPLICATION_ENV dev';

$options = getopt('', [
    'quality::',
    'max-width::',
    'max-height::',
    'log-file::',
    'force',
    'dry-run',
    'help',
]);

if (isset($options['help'])) {
    echo <<<TXT
Сжимает изображения в ./upload для dev-сайтов на Bitrix.

Использование:
  php .dev/tools/cuj.php [options]

Опции:
  --quality=35                              Качество JPEG/WebP, 0..100 (для PNG влияет на уровень сжатия)
  --max-width=320                           Уменьшать, если ширина больше
  --max-height=320                          Уменьшать, если высота больше
  --log-file=compress-upload-success.log    Имя лога успешной обработки внутри ./upload
  --force                                   Игнорировать лог успеха и обработать файлы заново
  --dry-run                                 Показать, что будет обработано, без изменений файлов
  --help                                    Показать эту справку

Правила:
  - Запускать только из корня сайта
  - Требуется ./.htaccess со строкой: SetEnv APPLICATION_ENV dev
  - Обрабатываются JPG/JPEG/PNG/WebP внутри ./upload
  - Пропускаются ./upload/resize_cache и служебные каталоги
  - При следующем запуске пропускаются файлы, уже отмеченные как [OK] в логе

TXT;
    exit(0);
}

$root = getcwd();
if (!$root) {
    fail('Не удалось определить текущий рабочий каталог.');
}

$htaccessPath = $root . '/.htaccess';
$uploadDir = $root . '/upload';

if (!is_file($htaccessPath) || !is_dir($uploadDir)) {
    fail('Запустите скрипт из корня сайта, где есть .htaccess и ./upload.');
}

$htaccess = @file_get_contents($htaccessPath);
if ($htaccess === false) {
    fail('Не удалось прочитать .htaccess');
}

if (strpos($htaccess, DEFAULT_HTACCESS_MARKER) === false) {
    fail('Dev-маркер не найден в .htaccess: ' . DEFAULT_HTACCESS_MARKER);
}

if (!extension_loaded('gd')) {
    fail('Требуется расширение PHP GD.');
}

$quality = intOption($options, 'quality', DEFAULT_QUALITY, 0, 100);
$maxWidth = intOption($options, 'max-width', DEFAULT_MAX_WIDTH, 1, 50000);
$maxHeight = intOption($options, 'max-height', DEFAULT_MAX_HEIGHT, 1, 50000);
$logFileName = stringOption($options, 'log-file', DEFAULT_LOG_FILE);
$force = isset($options['force']);
$dryRun = isset($options['dry-run']);

if ($logFileName === '' || basename($logFileName) !== $logFileName) {
    fail('--log-file должен быть именем файла внутри ./upload без обхода каталогов.');
}

$logPath = $uploadDir . '/' . $logFileName;

$lockPath = $uploadDir . '/compress-upload-jpg.lock';
$lockHandle = @fopen($lockPath, 'cb');
if ($lockHandle === false) {
    fail('Не удалось создать lock-файл: ' . $lockPath);
}

if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    fail('Скрипт уже запущен. Lock-файл: ' . $lockPath);
}

@ftruncate($lockHandle, 0);
@fwrite($lockHandle, sprintf(
    "pid=%d started=%s\n",
    getmypid(),
    date('c')
));

register_shutdown_function(static function () use ($lockHandle, $lockPath): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
    @unlink($lockPath);
});
$skipDirNames = [
        'resize_cache',
        'tmp',
        '.git',
        '.svn',
    ];

$alreadyProcessed = $force ? [] : loadProcessedFromLog($logPath);
$successHandle = null;

if (!$dryRun) {
    $successHandle = @fopen($logPath, 'ab');
    if ($successHandle === false) {
        fail('Не удалось открыть лог-файл для добавления: ' . $logPath);
    }

    fwrite($successHandle, sprintf(
        "=== %s | quality=%d | max=%dx%d | force=%s ===\n",
        date('Y-m-d H:i:s'),
        $quality,
        $maxWidth,
        $maxHeight,
        $force ? 'yes' : 'no'
    ));
}

$stats = [
    'found' => 0,
    'processed' => 0,
    'skipped_log' => 0,
    'skipped_invalid' => 0,
    'errors' => 0,
    'bytes_before' => 0,
    'bytes_after' => 0,
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($skipDirNames, $logFileName): bool {
            $name = $current->getFilename();

            if ($current->isDir()) {
                return !in_array($name, $skipDirNames, true);
            }

            if ($name === $logFileName) {
                return false;
            }

            return true;
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        continue;
    }

    $relativePath = normalizeRelativePath($root, $path);
    $stats['found']++;

    if (!$force && isset($alreadyProcessed[$relativePath])) {
        $stats['skipped_log']++;
        continue;
    }

    $beforeSize = @filesize($path);
    if ($beforeSize === false) {
        $beforeSize = 0;
    }

    try {
        $imageInfo = @getimagesize($path);
        $imageType = $imageInfo[2] ?? null;
        if (!$imageInfo || !isSupportedImageType($imageType)) {
            $stats['skipped_invalid']++;
            continue;
        }

        [$srcWidth, $srcHeight] = $imageInfo;
        $ratio = min(
            $maxWidth / max($srcWidth, 1),
            $maxHeight / max($srcHeight, 1),
            1
        );

        $dstWidth = max(1, (int) floor($srcWidth * $ratio));
        $dstHeight = max(1, (int) floor($srcHeight * $ratio));

        if ($dryRun) {
            $stats['processed']++;
            $stats['bytes_before'] += $beforeSize;
            $stats['bytes_after'] += $beforeSize;
            echo sprintf("[DRY] %s\n", $relativePath);
            continue;
        }

        $source = createImageResource($path, $imageType);
        if (!$source) {
            throw new RuntimeException('Не удалось открыть изображение');
        }

        $result = $source;
        if ($ratio < 1) {
            $result = imagecreatetruecolor($dstWidth, $dstHeight);
            if (!$result) {
                imagedestroy($source);
                throw new RuntimeException('Не удалось выделить память под уменьшенное изображение');
            }

            prepareDestinationCanvas($result, $imageType);

            if (!imagecopyresampled(
                $result,
                $source,
                0,
                0,
                0,
                0,
                $dstWidth,
                $dstHeight,
                $srcWidth,
                $srcHeight
            )) {
                imagedestroy($source);
                imagedestroy($result);
                throw new RuntimeException('Ошибка ресемплинга изображения');
            }
        }

        if (!saveImageResource($result, $path, $imageType, $quality)) {
            if ($result !== $source) {
                imagedestroy($result);
            }
            imagedestroy($source);
            throw new RuntimeException('Не удалось сохранить изображение');
        }

        if ($result !== $source) {
            imagedestroy($result);
        }
        imagedestroy($source);

        clearstatcache(true, $path);
        $afterSize = @filesize($path);
        if ($afterSize === false) {
            $afterSize = $beforeSize;
        }

        $stats['processed']++;
        $stats['bytes_before'] += $beforeSize;
        $stats['bytes_after'] += $afterSize;

        fwrite($successHandle, sprintf(
            "[OK] %s | before=%d | after=%d | saved=%d\n",
            $relativePath,
            $beforeSize,
            $afterSize,
            max(0, $beforeSize - $afterSize)
        ));
    } catch (Throwable $e) {
        $stats['errors']++;
        fwrite(STDERR, sprintf("[ERR] %s :: %s\n", $relativePath, $e->getMessage()));
    }
}

if ($successHandle) {
    fwrite($successHandle, sprintf(
        "SUMMARY found=%d processed=%d skipped_log=%d skipped_invalid=%d errors=%d bytes_before=%d bytes_after=%d saved=%d\n\n",
        $stats['found'],
        $stats['processed'],
        $stats['skipped_log'],
        $stats['skipped_invalid'],
        $stats['errors'],
        $stats['bytes_before'],
        $stats['bytes_after'],
        max(0, $stats['bytes_before'] - $stats['bytes_after'])
    ));
    fclose($successHandle);
}

echo sprintf(
    "Готово. Найдено: %d\nОбработано: %d\nПропущено по логу: %d\nНевалидных: %d\nОшибок: %d\nСэкономлено: %s\n",
    $stats['found'],
    $stats['processed'],
    $stats['skipped_log'],
    $stats['skipped_invalid'],
    $stats['errors'],
    formatBytes(max(0, $stats['bytes_before'] - $stats['bytes_after']))
);

function loadProcessedFromLog(string $logPath): array
{
    if (!is_file($logPath) || !is_readable($logPath)) {
        return [];
    }

    $processed = [];
    $handle = fopen($logPath, 'rb');
    if ($handle === false) {
        return [];
    }

    while (($line = fgets($handle)) !== false) {
        if (preg_match('/^\[OK\]\s+(.+?)\s+\|/u', trim($line), $matches)) {
            $processed[$matches[1]] = true;
        }
    }

    fclose($handle);
    return $processed;
}

function isSupportedImageType(?int $imageType): bool
{
    return in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
}

function createImageResource(string $path, int $imageType)
{
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($path);
        case IMAGETYPE_WEBP:
            if (!function_exists('imagecreatefromwebp')) {
                return false;
            }
            return @imagecreatefromwebp($path);
        default:
            return false;
    }
}

function saveImageResource($resource, string $path, int $imageType, int $quality): bool
{
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            @imageinterlace($resource, true);
            return (bool) @imagejpeg($resource, $path, $quality);
        case IMAGETYPE_PNG:
            $compression = (int) round((100 - $quality) * 9 / 100);
            $compression = max(0, min(9, $compression));
            return (bool) @imagepng($resource, $path, $compression);
        case IMAGETYPE_WEBP:
            if (!function_exists('imagewebp')) {
                return false;
            }
            return (bool) @imagewebp($resource, $path, $quality);
        default:
            return false;
    }
}

function prepareDestinationCanvas($canvas, int $imageType): void
{
    if (!in_array($imageType, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return;
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, imagesx($canvas), imagesy($canvas), $transparent);
}

function intOption(array $options, string $name, int $default, int $min, int $max): int
{
    if (!array_key_exists($name, $options) || $options[$name] === false) {
        return $default;
    }

    $value = filter_var($options[$name], FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
        fail(sprintf('Некорректное значение --%s. Ожидается целое число в диапазоне %d..%d.', $name, $min, $max));
    }

    return $value;
}

function stringOption(array $options, string $name, string $default): string
{
    if (!array_key_exists($name, $options) || $options[$name] === false) {
        return $default;
    }

    return trim((string) $options[$name]);
}

function normalizeRelativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);

    if (strpos($path, $root . '/') === 0) {
        return ltrim(substr($path, strlen($root)), '/');
    }

    return ltrim($path, '/');
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $index = 0;

    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return sprintf('%.2f %s', $value, $units[$index]);
}

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
