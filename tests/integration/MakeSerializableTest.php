<?php

namespace yellowrobot\courier\tests\integration;

use PHPUnit\Framework\TestCase;
use yellowrobot\courier\services\HookService;

/**
 * Stub element implementing ElementInterface with a real $id property.
 */
class StubElement extends \craft\base\Element
{
    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

/**
 * Tests HookService::_makeSerializable via reflection.
 */
class MakeSerializableTest extends TestCase
{
    private HookService $service;
    private \ReflectionMethod $method;

    protected function setUp(): void
    {
        $this->service = new HookService();
        $this->method = new \ReflectionMethod(HookService::class, '_makeSerializable');
    }

    public function testScalarValuesPassThrough(): void
    {
        $input = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
        ];

        $result = $this->method->invoke($this->service, $input);

        $this->assertSame($input, $result);
    }

    public function testNestedArraysAreRecursed(): void
    {
        $input = [
            'nested' => [
                'deep' => [
                    'value' => 'found',
                ],
            ],
        ];

        $result = $this->method->invoke($this->service, $input);

        $this->assertSame('found', $result['nested']['deep']['value']);
    }

    public function testCraftElementsAreSerializedAsReferences(): void
    {
        $element = new StubElement(42);

        $input = ['entry' => $element];
        $result = $this->method->invoke($this->service, $input);

        $this->assertIsArray($result['entry']);
        $this->assertArrayHasKey('__elementType', $result['entry']);
        $this->assertArrayHasKey('__elementId', $result['entry']);
        $this->assertSame(42, $result['entry']['__elementId']);
    }

    public function testMultipleElementsInSamePayload(): void
    {
        $user = new StubElement(10);
        $entry = new StubElement(20);

        $input = [
            'user' => $user,
            'entry' => $entry,
            'subject' => 'Hello',
        ];

        $result = $this->method->invoke($this->service, $input);

        $this->assertSame(10, $result['user']['__elementId']);
        $this->assertSame(20, $result['entry']['__elementId']);
        $this->assertSame('Hello', $result['subject']);
    }

    public function testNestedElementsInsideArrays(): void
    {
        $element = new StubElement(99);

        $input = [
            'data' => [
                'element' => $element,
                'label' => 'test',
            ],
        ];

        $result = $this->method->invoke($this->service, $input);

        $this->assertSame(99, $result['data']['element']['__elementId']);
        $this->assertSame('test', $result['data']['label']);
    }

    public function testEmptyArrayInput(): void
    {
        $result = $this->method->invoke($this->service, []);
        $this->assertSame([], $result);
    }

    public function testMixedPayloadRoundtripShape(): void
    {
        $element = new StubElement(5);

        $input = [
            'user' => $element,
            'name' => 'Alex',
            'tags' => ['craft', 'plugin'],
            'meta' => [
                'source' => 'test',
                'count' => 3,
            ],
        ];

        $result = $this->method->invoke($this->service, $input);

        // Element → reference
        $this->assertArrayHasKey('__elementType', $result['user']);
        $this->assertSame(5, $result['user']['__elementId']);
        // Scalar preserved
        $this->assertSame('Alex', $result['name']);
        // Array of scalars preserved
        $this->assertSame(['craft', 'plugin'], $result['tags']);
        // Nested associative array preserved
        $this->assertSame('test', $result['meta']['source']);
        $this->assertSame(3, $result['meta']['count']);
    }
}
