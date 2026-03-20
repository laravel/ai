<?php

namespace Tests\Feature;

use Laravel\Ai\Gateway\Prism\PrismSteps;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\StructuredStep;
use Laravel\Ai\Responses\Data\Usage;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Structured\Step as PrismStructuredStep;
use Prism\Prism\Text\Step as PrismTextStep;
use Prism\Prism\ValueObjects\Meta as PrismMeta;
use Prism\Prism\ValueObjects\Usage as PrismUsage;
use Tests\TestCase;

class PrismStepsTest extends TestCase
{
    private function fakeProvider(): Provider
    {
        $provider = $this->createStub(Provider::class);
        $provider->method('name')->willReturn('test');

        return $provider;
    }

    public function test_additional_content_is_passed_through_for_text_steps(): void
    {
        $prismStep = new PrismTextStep(
            text: 'Hello!',
            finishReason: PrismFinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            providerToolCalls: [],
            usage: new PrismUsage(10, 20),
            meta: new PrismMeta(id: 'test-id', model: 'test-model'),
            messages: [],
            systemPrompts: [],
            additionalContent: ['thinking' => 'I should greet the user.'],
        );

        $step = PrismSteps::toLaravelStep($prismStep, $this->fakeProvider());

        $this->assertInstanceOf(Step::class, $step);
        $this->assertEquals('Hello!', $step->text);
        $this->assertArrayHasKey('thinking', $step->additionalContent);
        $this->assertEquals('I should greet the user.', $step->additionalContent['thinking']);
    }

    public function test_additional_content_defaults_to_empty_array(): void
    {
        $prismStep = new PrismTextStep(
            text: 'Hello!',
            finishReason: PrismFinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            providerToolCalls: [],
            usage: new PrismUsage(10, 20),
            meta: new PrismMeta(id: 'test-id', model: 'test-model'),
            messages: [],
            systemPrompts: [],
        );

        $step = PrismSteps::toLaravelStep($prismStep, $this->fakeProvider());

        $this->assertEmpty($step->additionalContent);
    }

    public function test_additional_content_is_passed_through_for_structured_steps(): void
    {
        $prismStep = new PrismStructuredStep(
            text: '{"name":"Alice"}',
            finishReason: PrismFinishReason::Stop,
            usage: new PrismUsage(10, 20),
            meta: new PrismMeta(id: 'test-id', model: 'test-model'),
            messages: [],
            systemPrompts: [],
            additionalContent: ['thinking' => 'The user wants a name.'],
            structured: ['name' => 'Alice'],
        );

        $step = PrismSteps::toLaravelStructuredStep($prismStep, $this->fakeProvider());

        $this->assertInstanceOf(StructuredStep::class, $step);
        $this->assertEquals(['name' => 'Alice'], $step->structured);
        $this->assertArrayHasKey('thinking', $step->additionalContent);
        $this->assertEquals('The user wants a name.', $step->additionalContent['thinking']);
    }

    public function test_additional_content_is_included_in_to_array(): void
    {
        $step = new Step(
            text: 'Hello!',
            toolCalls: [],
            toolResults: [],
            finishReason: FinishReason::Stop,
            usage: new Usage,
            meta: new Meta,
            additionalContent: ['thinking' => 'Some reasoning content'],
        );

        $array = $step->toArray();

        $this->assertArrayHasKey('additional_content', $array);
        $this->assertEquals(['thinking' => 'Some reasoning content'], $array['additional_content']);
    }

    public function test_additional_content_is_empty_in_to_array_by_default(): void
    {
        $step = new Step(
            text: 'Hello!',
            toolCalls: [],
            toolResults: [],
            finishReason: FinishReason::Stop,
            usage: new Usage,
            meta: new Meta,
        );

        $array = $step->toArray();

        $this->assertArrayHasKey('additional_content', $array);
        $this->assertEquals([], $array['additional_content']);
    }
}
