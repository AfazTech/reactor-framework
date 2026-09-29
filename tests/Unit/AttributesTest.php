<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Text;
use Reactor\Attributes\Callback;
use Reactor\Attributes\Step;
use Reactor\Attributes\Group;
use Reactor\Attributes\UseMiddleware;
use Reactor\Attributes\Fallback;
use Reactor\Enums\MiddlewareMode;
use Reactor\Attributes\Middleware;

class AttributesTest extends TestCase
{
    /** @test */
    public function on_update_attribute_allows_any_type()
    {
        $attr = new OnUpdate('any');
        $this->assertTrue($attr->allows('message'));
        $this->assertTrue($attr->allows('callback_query'));
        $this->assertTrue($attr->allows('unknown_type'));
    }

    /** @test */
    public function on_update_attribute_restricts_to_specific_types()
    {
        $attr = new OnUpdate('message, callback_query');
        $this->assertTrue($attr->allows('message'));
        $this->assertTrue($attr->allows('callback_query'));
        $this->assertFalse($attr->allows('inline_query'));
        $this->assertFalse($attr->allows('edited_message'));
    }

    /** @test */
    public function on_update_attribute_accepts_array()
    {
        $attr = new OnUpdate(['message', 'edited_message']);
        $this->assertTrue($attr->allows('message'));
        $this->assertTrue($attr->allows('edited_message'));
        $this->assertFalse($attr->allows('callback_query'));
    }

    /** @test */
    public function text_attribute_has_correct_defaults()
    {
        $attr = new Text('hello');
        $this->assertEquals('hello', $attr->name);
        $this->assertFalse($attr->isCommand);
        $this->assertEquals(0, $attr->priority);
        $this->assertNull($attr->pattern);
    }

    /** @test */
    public function callback_attribute_has_correct_properties()
    {
        $attr = new Callback(data: 'btn_1', isStep: true, pattern: '/^btn_(\d+)$/', priority: 10);
        $this->assertEquals('btn_1', $attr->data);
        $this->assertTrue($attr->isStep);
        $this->assertEquals('/^btn_(\d+)$/', $attr->pattern);
        $this->assertEquals(10, $attr->priority);
    }

    /** @test */
    public function step_attribute_has_correct_defaults()
    {
        $attr = new Step(name: 'step1', nextStep: 'step2', autoClear: false);
        $this->assertEquals('step1', $attr->name);
        $this->assertEquals('step2', $attr->nextStep);
        $this->assertFalse($attr->autoClear);
    }

    /** @test */
    public function group_attribute_accepts_single_group()
    {
        $attr = new Group('admin');
        $this->assertEquals('admin', $attr->groups);
    }

    /** @test */
    public function group_attribute_accepts_array_of_groups()
    {
        $attr = new Group(['admin', 'moderator']);
        $this->assertEquals(['admin', 'moderator'], $attr->groups);
    }

    /** @test */
    public function use_middleware_attribute_accepts_array()
    {
        $attr = new UseMiddleware(['AuthMiddleware', 'LogMiddleware']);
        $this->assertEquals(['AuthMiddleware', 'LogMiddleware'], $attr->middlewares);
    }

    /** @test */
    public function fallback_attribute_has_priority()
    {
        $attr = new Fallback(priority: 10);
        $this->assertEquals(10, $attr->priority);
    }

    /** @test */
    public function middleware_attribute_has_default_values()
    {
        $attr = new Middleware();
        $this->assertEquals(0, $attr->priority);
        $this->assertEquals(MiddlewareMode::GLOBAL, $attr->mode);
        $this->assertEquals([], $attr->groups);
    }

    /** @test */
    public function middleware_attribute_accepts_custom_values()
    {
        $attr = new Middleware(
            priority: 50,
            mode: MiddlewareMode::GROUP,
            groups: ['api']
        );
        $this->assertEquals(50, $attr->priority);
        $this->assertEquals(MiddlewareMode::GROUP, $attr->mode);
        $this->assertEquals(['api'], $attr->groups);
    }
}
