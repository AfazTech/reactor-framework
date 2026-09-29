<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Routing\CommandParser;

class CommandParserTest extends TestCase
{
    private CommandParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CommandParser();
    }

    /** @test */
    public function it_detects_a_simple_command(): void
    {
        $this->assertTrue($this->parser->isCommand('/start'));
        $this->assertFalse($this->parser->isCommand('start'));
        $this->assertFalse($this->parser->isCommand(''));
        $this->assertFalse($this->parser->isCommand('hello /start'));
    }

    /** @test */
    public function it_parses_a_bare_command(): void
    {
        $parsed = $this->parser->parse('/start');

        $this->assertNotNull($parsed);
        $this->assertSame('/start', $parsed['name']);
        $this->assertSame('/start', $parsed['raw']);
        $this->assertNull($parsed['botName']);
        $this->assertSame([], $parsed['args']);
    }

    /** @test */
    public function it_parses_a_command_with_arguments(): void
    {
        $parsed = $this->parser->parse('/echo hello world');

        $this->assertNotNull($parsed);
        $this->assertSame('/echo', $parsed['name']);
        $this->assertNull($parsed['botName']);
        $this->assertSame(['hello', 'world'], $parsed['args']);
    }

    /** @test */
    public function it_strips_bot_name_from_command(): void
    {
        $parsed = $this->parser->parse('/start@MyBot');

        $this->assertNotNull($parsed);
        $this->assertSame('/start', $parsed['name']);
        $this->assertSame('/start@MyBot', $parsed['raw']);
        $this->assertSame('MyBot', $parsed['botName']);
        $this->assertSame([], $parsed['args']);
    }

    /** @test */
    public function it_parses_command_with_bot_name_and_arguments(): void
    {
        $parsed = $this->parser->parse('/echo@MyBot foo bar');

        $this->assertNotNull($parsed);
        $this->assertSame('/echo', $parsed['name']);
        $this->assertSame('MyBot', $parsed['botName']);
        $this->assertSame(['foo', 'bar'], $parsed['args']);
    }

    /** @test */
    public function it_handles_multiple_whitespace_kinds(): void
    {
        $parsed = $this->parser->parse("/echo\tfoo\nbar   baz");

        $this->assertNotNull($parsed);
        $this->assertSame('/echo', $parsed['name']);
        $this->assertSame(['foo', 'bar', 'baz'], $parsed['args']);
    }

    /** @test */
    public function it_trims_surrounding_whitespace(): void
    {
        $parsed = $this->parser->parse("   /start   arg   ");

        $this->assertNotNull($parsed);
        $this->assertSame('/start', $parsed['name']);
        $this->assertSame(['arg'], $parsed['args']);
    }

    /** @test */
    public function it_rejects_non_command_text(): void
    {
        $this->assertNull($this->parser->parse('hello'));
        $this->assertNull($this->parser->parse(''));
        $this->assertNull($this->parser->parse('  '));
    }

    /** @test */
    public function it_rejects_a_lone_slash(): void
    {
        $this->assertNull($this->parser->parse('/'));
        $this->assertNull($this->parser->parse('/ arg1'));
    }

    /** @test */
    public function it_preserves_command_name_case(): void
    {
        $parsed = $this->parser->parse('/START');

        $this->assertNotNull($parsed);
        $this->assertSame('/START', $parsed['name']);
    }

    /** @test */
    public function it_splits_on_first_at_sign_only(): void
    {
        // Defensive: an @ inside the arguments must not be consumed.
        $parsed = $this->parser->parse('/echo@MyBot user@example.com');

        $this->assertNotNull($parsed);
        $this->assertSame('/echo', $parsed['name']);
        $this->assertSame('MyBot', $parsed['botName']);
        $this->assertSame(['user@example.com'], $parsed['args']);
    }

    /** @test */
    public function it_treats_leading_whitespace_as_non_command(): void
    {
        // After trim(), the leading whitespace is gone and the slash is
        // at position 0; a slash preceded by non-trimmable noise would
        // not be a command, but spaces/tabs are trimmed.
        $this->assertTrue($this->parser->isCommand('/start'));
        $this->assertNotNull($this->parser->parse('  /start  '));
    }
}
