<?php
declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\Sanitizer\MonitoringDataSanitizer;
use PHPUnit\Framework\TestCase;

final class MonitoringDataSanitizerTest extends TestCase
{
    private MonitoringDataSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MonitoringDataSanitizer();
    }

    public function testSanitizeFilename(): void
    {
        $this->assertSame('import.xml', $this->sanitizer->sanitizeFilename('/var/www/data/import.xml'));
        $this->assertSame('offers.xml', $this->sanitizer->sanitizeFilename('/home/user/uploads/offers.xml'));
        $this->assertSame('simple.xml', $this->sanitizer->sanitizeFilename('simple.xml'));
    }

    public function testSanitizeErrorHandlesNull(): void
    {
        $this->assertNull($this->sanitizer->sanitizeError(null));
    }

    public function testSanitizeError(): void
    {
        $error = 'PDOException: Failed to connect to postgresql://admin:secret@localhost:5432/db in /home/user/app/src/Repo.php:12 and /var/www/html/error.log. Payload: <CommerceML Inn="7701234567">...</CommerceML>';
        $sanitized = $this->sanitizer->sanitizeError($error);

        $this->assertNotNull($sanitized);
        $this->assertStringContainsString('[REDACTED_DSN]', $sanitized);
        $this->assertStringContainsString('[REDACTED_PATH]', $sanitized);
        $this->assertStringContainsString('[XML_REDACTED]', $sanitized);
        $this->assertStringNotContainsString('secret', $sanitized);
        $this->assertStringNotContainsString('7701234567', $sanitized);
        $this->assertStringNotContainsString('/home/user', $sanitized);
        $this->assertStringNotContainsString('/var/www/html', $sanitized);
    }
}
