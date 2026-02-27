<?php

namespace Tests\Unit\Exceptions;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\CliProcessException;
use Laravel\Ai\Exceptions\FailoverableException;
use PHPUnit\Framework\TestCase;

class CliProcessExceptionTest extends TestCase
{
    public function test_is_instance_of_ai_exception(): void
    {
        $e = new CliProcessException('test error');
        $this->assertInstanceOf(AiException::class, $e);
    }

    public function test_is_instance_of_failoverable_exception(): void
    {
        $e = new CliProcessException('test error');
        $this->assertInstanceOf(FailoverableException::class, $e);
    }

    public function test_carries_message_correctly(): void
    {
        $e = new CliProcessException('CLI failed');
        $this->assertSame('CLI failed', $e->getMessage());
    }

    public function test_previous_exception_preserved(): void
    {
        $prev = new \RuntimeException('root cause');
        $e = new CliProcessException('cli failed', previous: $prev);
        $this->assertSame('cli failed', $e->getMessage());
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame('root cause', $e->getPrevious()->getMessage());
    }

    public function test_default_code_is_zero(): void
    {
        $e = new CliProcessException('error');
        $this->assertSame(0, $e->getCode());
    }
}
