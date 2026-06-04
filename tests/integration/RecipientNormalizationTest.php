<?php

namespace yellowrobot\courier\tests\integration;

use PHPUnit\Framework\TestCase;

/**
 * Tests recipient normalization logic from HookService::_handleEvent.
 */
class RecipientNormalizationTest extends TestCase
{
    /**
     * Simulate the recipient normalization logic from HookService::_handleEvent.
     * This is the exact same code path, extracted for testability.
     */
    private function normalizeRecipients(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw));
    }

    // ─── String inputs ───────────────────────────────────────

    public function testSingleEmailString(): void
    {
        $result = $this->normalizeRecipients('user@example.com');
        $this->assertSame(['user@example.com'], $result);
    }

    public function testCommaSeparatedString(): void
    {
        $result = $this->normalizeRecipients('a@example.com, b@example.com, c@example.com');
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $result);
    }

    public function testCommaSeparatedWithExtraSpaces(): void
    {
        $result = $this->normalizeRecipients('  a@example.com ,  b@example.com  ');
        $this->assertSame(['a@example.com', 'b@example.com'], $result);
    }

    public function testEmptyString(): void
    {
        $result = $this->normalizeRecipients('');
        $this->assertSame([], $result);
    }

    public function testStringWithOnlyCommasAndSpaces(): void
    {
        $result = $this->normalizeRecipients(', , ,');
        $this->assertSame([], $result);
    }

    // ─── Array inputs ────────────────────────────────────────

    public function testArrayOfEmails(): void
    {
        $result = $this->normalizeRecipients(['a@example.com', 'b@example.com']);
        $this->assertSame(['a@example.com', 'b@example.com'], $result);
    }

    public function testArrayWithEmptyStringsFiltered(): void
    {
        $result = $this->normalizeRecipients(['a@example.com', '', 'b@example.com', '']);
        $this->assertSame(['a@example.com', 'b@example.com'], $result);
    }

    public function testArrayWithNullsFiltered(): void
    {
        $result = $this->normalizeRecipients(['a@example.com', null, 'b@example.com']);
        $this->assertSame(['a@example.com', 'b@example.com'], $result);
    }

    public function testEmptyArray(): void
    {
        $result = $this->normalizeRecipients([]);
        $this->assertSame([], $result);
    }

    public function testArrayKeysAreReindexed(): void
    {
        // array_filter preserves keys — array_values should fix this
        $input = [0 => 'a@example.com', 1 => '', 2 => 'b@example.com'];
        $result = $this->normalizeRecipients($input);

        $this->assertSame([0, 1], array_keys($result));
        $this->assertSame(['a@example.com', 'b@example.com'], $result);
    }

    // ─── Invalid inputs (type safety) ────────────────────────

    public function testNullReturnsEmpty(): void
    {
        $result = $this->normalizeRecipients(null);
        $this->assertSame([], $result);
    }

    public function testIntegerReturnsEmpty(): void
    {
        $result = $this->normalizeRecipients(42);
        $this->assertSame([], $result);
    }

    public function testBoolReturnsEmpty(): void
    {
        $result = $this->normalizeRecipients(false);
        $this->assertSame([], $result);
    }

    public function testObjectReturnsEmpty(): void
    {
        $result = $this->normalizeRecipients(new \stdClass());
        $this->assertSame([], $result);
    }

    // ─── CC/BCC normalization (same logic, with the ?: [] fallback) ───

    private function normalizeCcBcc(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        return is_array($raw) ? array_values(array_filter($raw)) : [];
    }

    public function testCcStringNormalization(): void
    {
        $result = $this->normalizeCcBcc('cc1@example.com, cc2@example.com');
        $this->assertSame(['cc1@example.com', 'cc2@example.com'], $result);
    }

    public function testCcNullReturnsEmpty(): void
    {
        $result = $this->normalizeCcBcc(null);
        $this->assertSame([], $result);
    }

    public function testCcFalseReturnsEmpty(): void
    {
        $result = $this->normalizeCcBcc(false);
        $this->assertSame([], $result);
    }

    public function testCcEmptyArrayReturnsEmpty(): void
    {
        $result = $this->normalizeCcBcc([]);
        $this->assertSame([], $result);
    }
}
