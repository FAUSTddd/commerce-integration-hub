<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Messenger\ImportRunContext;
use App\Messenger\ImportRunStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class ImportRunStampTest extends TestCase
{
    public function testStampHoldsId(): void
    {
        $stamp = new ImportRunStamp(42);
        self::assertSame(42, $stamp->getImportRunId());
    }

    public function testContextLifecycle(): void
    {
        $context = new ImportRunContext();
        self::assertNull($context->getCurrentRunId());

        $context->setCurrentRunId(101);
        self::assertSame(101, $context->getCurrentRunId());

        $context->reset();
        self::assertNull($context->getCurrentRunId());
    }

    public function testPhpSerializerPreservesStamp(): void
    {
        $serializer = new PhpSerializer();
        $message = new \stdClass();
        $message->title = 'test';

        $envelope = new Envelope($message, [new ImportRunStamp(777)]);

        $encoded = $serializer->encode($envelope);
        self::assertArrayHasKey('body', $encoded);

        $decoded = $serializer->decode($encoded);
        $stamp = $decoded->last(ImportRunStamp::class);

        self::assertInstanceOf(ImportRunStamp::class, $stamp);
        self::assertSame(777, $stamp->getImportRunId());
    }
}
