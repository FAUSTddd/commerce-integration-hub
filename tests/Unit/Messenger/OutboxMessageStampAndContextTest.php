<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Messenger\ImportRunContext;
use App\Messenger\OutboxMessageStamp;
use PHPUnit\Framework\TestCase;

final class OutboxMessageStampAndContextTest extends TestCase
{
    public function testStampHoldsAndReturnsOutboxId(): void
    {
        $stamp = new OutboxMessageStamp(42);
        $this->assertSame(42, $stamp->getOutboxId());
    }

    public function testContextSetsGetsAndResetsOutboxId(): void
    {
        $context = new ImportRunContext();
        $this->assertNull($context->getCurrentOutboxId());

        $context->setCurrentOutboxId(123);
        $this->assertSame(123, $context->getCurrentOutboxId());

        $context->reset();
        $this->assertNull($context->getCurrentOutboxId());
    }

    public function testContextIsolationBetweenMultipleWorkersOrMessages(): void
    {
        $context = new ImportRunContext();

        // Message 1
        $context->setCurrentRunId(10);
        $context->setCurrentOutboxId(55);
        $context->setCurrentAttempt(1);
        $context->setHandledSuccessfully(true);

        $this->assertSame(55, $context->getCurrentOutboxId());

        // Context reset after worker completes message 1
        $context->reset();

        $this->assertNull($context->getCurrentOutboxId());
        $this->assertNull($context->getCurrentRunId());
        $this->assertFalse($context->isHandledSuccessfully());

        // Message 2 without OutboxMessageStamp
        $context->setCurrentRunId(11);
        $context->setCurrentAttempt(2);

        $this->assertNull($context->getCurrentOutboxId());
        $this->assertSame(11, $context->getCurrentRunId());
    }
}
