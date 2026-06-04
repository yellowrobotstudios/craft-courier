<?php

namespace yellowrobot\courier\tests\integration;

use PHPUnit\Framework\TestCase;
use yellowrobot\courier\traits\RehydratesVariablesTrait;

/**
 * Concrete class to test the trait in isolation.
 */
class RehydrateTestJob
{
    use RehydratesVariablesTrait;

    public function rehydrate(array $variables): array
    {
        return $this->_rehydrateVariables($variables);
    }
}

/**
 * Tests RehydratesVariablesTrait — the queue job variable rehydration logic.
 */
class RehydrateVariablesTest extends TestCase
{
    private RehydrateTestJob $job;

    protected function setUp(): void
    {
        $this->job = new RehydrateTestJob();
    }

    public function testScalarValuesPassThrough(): void
    {
        $input = [
            'string' => 'hello',
            'int' => 42,
            'bool' => true,
            'null' => null,
        ];

        $result = $this->job->rehydrate($input);

        $this->assertSame($input, $result);
    }

    public function testNestedArraysAreRecursed(): void
    {
        $input = [
            'meta' => [
                'nested' => [
                    'value' => 'deep',
                ],
            ],
        ];

        $result = $this->job->rehydrate($input);

        $this->assertSame('deep', $result['meta']['nested']['value']);
    }

    public function testNonElementArraysAreRecursed(): void
    {
        // An array with __elementType but NOT __elementId should be recursed normally
        $input = [
            'data' => [
                '__elementType' => 'something',
                'other' => 'value',
            ],
        ];

        $result = $this->job->rehydrate($input);

        $this->assertIsArray($result['data']);
        $this->assertSame('something', $result['data']['__elementType']);
        $this->assertSame('value', $result['data']['other']);
    }

    public function testEmptyArrayInput(): void
    {
        $result = $this->job->rehydrate([]);
        $this->assertSame([], $result);
    }

    public function testElementReferenceWithMissingElementDropsKey(): void
    {
        $elementsService = new class {
            public function getElementById(int $id, ?string $elementType = null, ?int $siteId = null, array $criteria = []): ?object
            {
                return null;
            }
        };
        \Craft::$app->set('elements', $elementsService);

        $input = [
            'user' => [
                '__elementType' => 'craft\\elements\\User',
                '__elementId' => 999999,
            ],
            'name' => 'kept',
        ];

        $result = $this->job->rehydrate($input);

        $this->assertArrayNotHasKey('user', $result);
        $this->assertSame('kept', $result['name']);
    }

    public function testElementReferenceWithFoundElementRehydrates(): void
    {
        $mockElement = new StubElement(42);
        $elementsService = new class($mockElement) {
            private object $element;
            public function __construct(object $element) { $this->element = $element; }
            public function getElementById(int $id, ?string $elementType = null, ?int $siteId = null, array $criteria = []): ?object
            {
                return $this->element;
            }
        };
        \Craft::$app->set('elements', $elementsService);

        $input = [
            'user' => [
                '__elementType' => 'craft\\elements\\User',
                '__elementId' => 42,
            ],
            'title' => 'kept',
        ];

        $result = $this->job->rehydrate($input);

        $this->assertSame($mockElement, $result['user']);
        $this->assertSame('kept', $result['title']);
    }
}
