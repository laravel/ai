<?php

namespace Tests\Feature;

use Laravel\Ai\Moderation;
use Laravel\Ai\Prompts\ModerationPrompt;
use Laravel\Ai\Responses\ModerationResult;
use RuntimeException;
use Tests\TestCase;

class ModerationFakeTest extends TestCase
{
    public function test_can_fake_moderation(): void
    {
        Moderation::fake();

        $response = Moderation::of('Hello world')->moderate();

        $this->assertCount(1, $response);
        $this->assertFalse($response->first()->flagged);
        $this->assertIsArray($response->first()->categories);
        $this->assertIsArray($response->first()->categoryScores);
    }

    public function test_can_fake_moderation_with_multiple_inputs(): void
    {
        Moderation::fake();

        $response = Moderation::of(['Hello', 'World', 'Test'])->moderate();

        $this->assertCount(3, $response);
        $this->assertFalse($response->flagged());
    }

    public function test_can_fake_moderation_with_custom_response(): void
    {
        $customResult = [
            'flagged' => true,
            'categories' => ['hate' => true, 'violence' => false],
            'category_scores' => ['hate' => 0.95, 'violence' => 0.01],
        ];

        Moderation::fake([$customResult]);

        $response = Moderation::of('Inappropriate content')->moderate();

        $this->assertTrue($response->first()->flagged);
        $this->assertTrue($response->first()->categories['hate']);
        $this->assertEquals(0.95, $response->first()->categoryScores['hate']);
    }

    public function test_can_fake_moderation_with_closure(): void
    {
        Moderation::fake(function (ModerationPrompt $prompt) {
            $inputCount = $prompt->count();
            
            return array_fill(0, $inputCount, [
                'flagged' => $prompt->contains('bad'),
                'categories' => ['hate' => $prompt->contains('bad')],
                'category_scores' => ['hate' => $prompt->contains('bad') ? 0.9 : 0.1],
            ]);
        });

        $safeResponse = Moderation::of('Hello world')->moderate();
        $this->assertFalse($safeResponse->first()->flagged);

        $flaggedResponse = Moderation::of('bad content')->moderate();
        $this->assertTrue($flaggedResponse->first()->flagged);
    }

    public function test_can_assert_moderation_generated(): void
    {
        Moderation::fake();

        Moderation::of('Hello world')->moderate();

        Moderation::assertGenerated(function (ModerationPrompt $prompt) {
            return $prompt->contains('Hello');
        });
    }

    public function test_can_assert_moderation_not_generated(): void
    {
        Moderation::fake();

        Moderation::of('Hello world')->moderate();

        Moderation::assertNotGenerated(function (ModerationPrompt $prompt) {
            return $prompt->contains('Goodbye');
        });
    }

    public function test_can_assert_nothing_generated(): void
    {
        Moderation::fake();

        Moderation::assertNothingGenerated();
    }

    public function test_response_flagged_method_detects_any_flagged_content(): void
    {
        Moderation::fake([
            [
                'flagged' => false,
                'categories' => [],
                'category_scores' => [],
            ],
            [
                'flagged' => true,
                'categories' => ['hate' => true],
                'category_scores' => ['hate' => 0.9],
            ],
        ]);

        $response = Moderation::of(['Safe content', 'Bad content'])->moderate();

        $this->assertTrue($response->flagged());
    }

    public function test_response_flagged_method_returns_false_when_all_safe(): void
    {
        Moderation::fake();

        $response = Moderation::of(['Safe 1', 'Safe 2'])->moderate();

        $this->assertFalse($response->flagged());
    }

    public function test_can_prevent_stray_moderations(): void
    {
        $this->expectException(RuntimeException::class);

        Moderation::fake()->preventStrayModerations();

        Moderation::of('Hello world')->moderate();
    }

    public function test_moderation_result_is_arrayable(): void
    {
        Moderation::fake();

        $response = Moderation::of('Test')->moderate();
        $result = $response->first();

        $array = $result->toArray();

        $this->assertArrayHasKey('flagged', $array);
        $this->assertArrayHasKey('categories', $array);
        $this->assertArrayHasKey('category_scores', $array);
    }

    public function test_moderation_response_is_countable(): void
    {
        Moderation::fake();

        $response = Moderation::of(['Input 1', 'Input 2', 'Input 3'])->moderate();

        $this->assertCount(3, $response);
    }

    public function test_moderation_response_is_iterable(): void
    {
        Moderation::fake();

        $response = Moderation::of(['A', 'B', 'C'])->moderate();

        $results = [];
        foreach ($response as $result) {
            $results[] = $result;
        }

        $this->assertCount(3, $results);
        $this->assertContainsOnlyInstancesOf(ModerationResult::class, $results);
    }

    public function test_single_string_input_creates_single_result(): void
    {
        Moderation::fake();

        $response = Moderation::of('Single input')->moderate();

        $this->assertCount(1, $response);
    }

    public function test_array_input_creates_multiple_results(): void
    {
        Moderation::fake();

        $inputs = ['First', 'Second', 'Third', 'Fourth'];
        $response = Moderation::of($inputs)->moderate();

        $this->assertCount(4, $response);
    }
}
