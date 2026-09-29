<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\UpdateTypeResolver;

class UpdateTypeResolverTest extends TestCase
{
    private UpdateTypeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new UpdateTypeResolver();
    }

    /** @test */
    public function it_returns_message_type_when_message_key_exists()
    {
        $update = ['message' => ['text' => 'hello']];
        $this->assertEquals('message', $this->resolver->getType($update));
    }

    /** @test */
    public function it_returns_callback_query_type_when_callback_query_key_exists()
    {
        $update = ['callback_query' => ['data' => 'some_data']];
        $this->assertEquals('callback_query', $this->resolver->getType($update));
    }

    /** @test */
    public function it_returns_unknown_when_no_known_type_exists()
    {
        $update = ['unknown_key' => 'value'];
        $this->assertEquals('unknown', $this->resolver->getType($update));
    }

    /** @test */
    public function it_prioritizes_first_matching_key_in_the_list()
    {
        $update = [
            'message' => ['text' => 'hi'],
            'callback_query' => ['data' => 'cb']
        ];
        $this->assertEquals('message', $this->resolver->getType($update));
    }

    /** @test */
    public function it_handles_empty_update()
    {
        $update = [];
        $this->assertEquals('unknown', $this->resolver->getType($update));
    }

    /** @test */
    public function it_returns_inline_query_type_when_present()
    {
        $update = ['inline_query' => ['query' => 'test']];
        $this->assertEquals('inline_query', $this->resolver->getType($update));
    }

    /** @test */
    public function it_returns_edited_message_type_when_present()
    {
        $update = ['edited_message' => ['text' => 'edited']];
        $this->assertEquals('edited_message', $this->resolver->getType($update));
    }
}
