<?php
declare(strict_types=1);

namespace App\Monitoring\Sanitizer;

class MonitoringDataSanitizer
{
    private const SECRETS_PATTERN = '#(postgresql|mysql|mssql)://[^@]+@[^:]+:\d+/\w+#i';
    private const ABS_PATH_PATTERN = '#(/home/[^\s]+|/var/www/[^\s]+)#';

    public function sanitizeFilename(string $filename): string
    {
        return basename($filename);
    }

    public function sanitizeError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $sanitized = preg_replace(self::SECRETS_PATTERN, '[REDACTED_DSN]', $error);
        $sanitized = preg_replace(self::ABS_PATH_PATTERN, '[REDACTED_PATH]', (string)$sanitized);
        $sanitized = preg_replace('#<[^>]+>#', '[XML_REDACTED]', (string)$sanitized);

        return $sanitized;
    }
}
