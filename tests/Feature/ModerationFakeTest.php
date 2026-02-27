<?php

namespace Tests\Feature;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Moderation;
use Laravel\Ai\Prompts\ModerationPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ModerationCategory;
use Laravel\Ai\Responses\ModerationResponse;
use RuntimeException;
use Tests\TestCase;

class ModerationFakeTest extends TestCase
{
    public function test_moderation_can_be_faked(): void
    {
        Moderation::fake();

        $response = Moderation::check('Hello world');

        $this->assertFalse($response->flagged);
        $this->assertCount(13, $response->categories);
        $this->assertInstanceOf(ModerationCategory::class, $response->categories[0]);
    }

    public function test_moderation_can_be_faked_with_no_predefined_responses(): void
    {
        Moderation::fake();

        $response = Moderation::check('First input');
        $this->assertFalse($response->flagged);
        $this->assertCount(13, $response->categories);

        $response = Moderation::check('Second input');
        $this->assertFalse($response->flagged);
        $this->assertCount(13, $response->categories);
    }

    public function test_moderation_can_be_faked_with_predefined_responses(): void
    {
        Moderation::fake([
            new ModerationResponse(
                true,
                [
                    new ModerationCategory('hate', true, 0.95),
                    new ModerationCategory('violence', false, 0.01),
                ],
                new Meta,
            ),
            new ModerationResponse(
                false,
                [
                    new ModerationCategory('hate', false, 0.01),
                ],
                new Meta,
            ),
        ]);

        $response = Moderation::check('First input');
        $this->assertTrue($response->flagged);
        $this->assertCount(2, $response->categories);
        $this->assertEquals(0.95, $response->category('hate')->score);
        $this->assertNull($response->category('nonexistent'));

        $response = Moderation::check('Second input');
        $this->assertFalse($response->flagged);
        $this->assertCount(1, $response->categories);
    }

    public function test_moderation_can_be_faked_with_category_arrays(): void
    {
        Moderation::fake([
            [
                new ModerationCategory('hate', true, 0.9),
                new ModerationCategory('violence', true, 0.8),
                new ModerationCategory('sexual', false, 0.01),
            ],
        ]);

        $response = Moderation::check('Hateful content');

        $this->assertTrue($response->flagged);
        $this->assertCount(3, $response->categories);
        $this->assertTrue($response->category('hate')->flagged);
        $this->assertTrue($response->category('violence')->flagged);
        $this->assertCount(2, $response->flagged());
        $this->assertEquals('hate', (string) $response->flagged()[0]);
    }

    public function test_moderation_can_be_faked_with_closure(): void
    {
        Moderation::fake(function (ModerationPrompt $prompt) {
            if ($prompt->contains('hate')) {
                return new ModerationResponse(
                    true,
                    [new ModerationCategory('hate', true, 0.99)],
                    new Meta,
                );
            }

            return new ModerationResponse(
                false,
                [new ModerationCategory('hate', false, 0.01)],
                new Meta,
            );
        });

        $response = Moderation::check('I hate this');
        $this->assertTrue($response->flagged);
        $this->assertEquals(0.99, $response->category('hate')->score);

        $response = Moderation::check('I love this');
        $this->assertFalse($response->flagged);
    }

    public function test_moderation_can_be_faked_with_a_single_closure_that_is_invoked_for_every_check(): void
    {
        Moderation::fake(function (ModerationPrompt $prompt) {
            return new ModerationResponse(
                false,
                [new ModerationCategory('custom', false, 0.001)],
                new Meta,
            );
        });

        $response = Moderation::check('First input');
        $this->assertFalse($response->flagged);
        $this->assertEquals('custom', $response->categories[0]->name);

        $response = Moderation::check('Second input');
        $this->assertFalse($response->flagged);
        $this->assertEquals('custom', $response->categories[0]->name);
    }

    public function test_moderation_can_be_faked_with_closures_in_response_array(): void
    {
        Moderation::fake([
            fn (ModerationPrompt $prompt) => new ModerationResponse(
                true,
                [new ModerationCategory('hate', true, 0.9)],
                new Meta,
            ),
            fn (ModerationPrompt $prompt) => new ModerationResponse(
                false,
                [new ModerationCategory('hate', false, 0.1)],
                new Meta,
            ),
        ]);

        $response = Moderation::check('First');
        $this->assertTrue($response->flagged);

        $response = Moderation::check('Second');
        $this->assertFalse($response->flagged);
    }

    public function test_can_assert_checked(): void
    {
        Moderation::fake();

        Moderation::check('Hello world');

        Moderation::assertChecked(function (ModerationPrompt $prompt) {
            return $prompt->contains('Hello');
        });
    }

    public function test_can_assert_not_checked(): void
    {
        Moderation::fake();

        Moderation::check('Hello world');

        Moderation::assertNotChecked(function (ModerationPrompt $prompt) {
            return $prompt->contains('Goodbye');
        });
    }

    public function test_can_assert_nothing_checked(): void
    {
        Moderation::fake();

        Moderation::assertNothingChecked();
    }

    public function test_moderation_can_prevent_stray_moderations(): void
    {
        $this->expectException(RuntimeException::class);

        Moderation::fake()->preventStrayModerations();

        Moderation::check('Hello world');
    }

    public function test_moderation_is_faked(): void
    {
        $this->assertFalse(Moderation::isFaked());

        Moderation::fake();

        $this->assertTrue(Moderation::isFaked());
    }

    public function test_check_accepts_ai_provider_enum(): void
    {
        Moderation::fake();

        Moderation::check('Enum moderation', provider: Lab::OpenAI);

        Moderation::assertChecked(fn (ModerationPrompt $prompt) => $prompt->contains('Enum moderation'));
    }

    public function test_check_accepts_provider_and_model(): void
    {
        Moderation::fake();

        Moderation::check('Test input', provider: 'openai', model: 'omni-moderation-latest');

        Moderation::assertChecked(function (ModerationPrompt $prompt) {
            return $prompt->input === 'Test input'
                && $prompt->model === 'omni-moderation-latest';
        });
    }

    public function test_fake_default_categories_are_present(): void
    {
        Moderation::fake();

        $response = Moderation::check('Test content');

        $expectedCategories = [
            'hate', 'hate/threatening', 'harassment', 'harassment/threatening',
            'illicit', 'illicit/violent', 'self-harm', 'self-harm/intent',
            'self-harm/instructions', 'sexual', 'sexual/minors', 'violence',
            'violence/graphic',
        ];

        foreach ($expectedCategories as $name) {
            $category = $response->category($name);
            $this->assertNotNull($category, "Category '{$name}' should exist.");
            $this->assertFalse($category->flagged);
            $this->assertEquals(0.0001, $category->score);
        }
    }

    public function test_prompt_input_is_recorded(): void
    {
        Moderation::fake();

        Moderation::check('This is a test input');

        Moderation::assertChecked(function (ModerationPrompt $prompt) {
            return $prompt->input === 'This is a test input';
        });
    }
}
