<?php

namespace Tests\Unit\Gateway\Prism;

use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\Prism\PrismMeta;
use Laravel\Ai\Responses\Data\Meta;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Meta as PrismMetaValueObject;
use Prism\Prism\ValueObjects\ProviderRateLimit;

class PrismMetaTest extends TestCase
{
    public function test_converts_prism_meta_with_all_fields(): void
    {
        $prismMeta = new PrismMetaValueObject(
            id: 'req_abc123',
            model: 'gpt-4',
            rateLimits: [
                new ProviderRateLimit(
                    name: 'daily',
                    limit: 1000,
                    remaining: 850
                ),
            ],
            serviceTier: 'auto'
        );

        $meta = PrismMeta::toLaravelMeta('provider-name', $prismMeta);

        $this->assertInstanceOf(Meta::class, $meta);
        $this->assertEquals('provider-name', $meta->provider);
        $this->assertEquals('gpt-4', $meta->model);
        $this->assertInstanceOf(Collection::class, $meta->extra);
        $this->assertTrue($meta->extra->has('id'));
        $this->assertEquals('req_abc123', $meta->extra->get('id'));
        $this->assertTrue($meta->extra->has('rate_limits'));
        $this->assertIsArray($meta->extra->get('rate_limits'));
        $this->assertTrue($meta->extra->has('service_tier'));
        $this->assertEquals('auto', $meta->extra->get('service_tier'));
    }

    public function test_converts_prism_meta_with_citations(): void
    {
        $prismMeta = new PrismMetaValueObject(
            id: 'req_abc123',
            model: 'gpt-4'
        );

        $citations = new Collection([
            ['title' => 'Test Citation', 'url' => 'https://example.com'],
        ]);

        $meta = PrismMeta::toLaravelMeta('provider-name', $prismMeta, $citations);

        $this->assertInstanceOf(Collection::class, $meta->citations);
        $this->assertCount(1, $meta->citations);
    }

    public function test_model_is_not_duplicated_in_extra(): void
    {
        $prismMeta = new PrismMetaValueObject(
            id: 'req_abc123',
            model: 'gpt-4',
            rateLimits: [],
            serviceTier: 'auto'
        );

        $meta = PrismMeta::toLaravelMeta('provider-name', $prismMeta);

        $this->assertFalse($meta->extra->has('model'));
        $this->assertEquals('gpt-4', $meta->model);
    }

    public function test_handles_null_prism_meta(): void
    {
        $meta = PrismMeta::toLaravelMeta('provider-name', null);

        $this->assertInstanceOf(Meta::class, $meta);
        $this->assertNull($meta->model);
        $this->assertInstanceOf(Collection::class, $meta->extra);
        $this->assertTrue($meta->extra->isEmpty());
    }

    public function test_extra_contains_id_even_when_only_id_and_model_provided(): void
    {
        $prismMeta = new PrismMetaValueObject(
            id: 'req_abc123',
            model: 'gpt-4'
        );

        $meta = PrismMeta::toLaravelMeta('provider-name', $prismMeta);

        $this->assertFalse($meta->extra->isEmpty());
        $this->assertTrue($meta->extra->has('id'));
        $this->assertEquals('req_abc123', $meta->extra->get('id'));
    }

    public function test_meta_toarray_includes_extra(): void
    {
        $prismMeta = new PrismMetaValueObject(
            id: 'req_abc123',
            model: 'gpt-4',
            rateLimits: [
                new ProviderRateLimit(
                    name: 'daily',
                    limit: 1000,
                    remaining: 850
                ),
            ],
            serviceTier: 'auto'
        );

        $meta = PrismMeta::toLaravelMeta('provider-name', $prismMeta);

        $array = $meta->toArray();

        $this->assertArrayHasKey('extra', $array);
        $this->assertArrayHasKey('id', $array['extra']);
        $this->assertArrayHasKey('rate_limits', $array['extra']);
        $this->assertArrayHasKey('service_tier', $array['extra']);
    }
}
