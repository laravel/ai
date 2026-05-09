<?php

use Laravel\Ai\Gateway\Bedrock\BedrockImageGateway;

function imageGateway(): object
{
    return new class extends BedrockImageGateway
    {
        public function callPrepareBody(string $model, string $prompt, ?string $size, ?string $quality): array
        {
            return $this->prepareImageRequestBody($model, $prompt, $size, [
                'quality' => $quality ?? 'standard',
                'size' => $size ?? '1:1',
            ]);
        }

        public function callParseResponse(string $model, array $result)
        {
            return $this->parseImageResponse($model, $result);
        }

        public function callParseSize(?string $size): array
        {
            return $this->parseSize($size);
        }
    };
}

test('parse size converts 2:3 ratio to portrait dimensions', function () {
    expect(imageGateway()->callParseSize('2:3'))->toEqual([768, 1152]);
});

test('parse size converts 3:2 ratio to landscape dimensions', function () {
    expect(imageGateway()->callParseSize('3:2'))->toEqual([1152, 768]);
});

test('parse size defaults to square dimensions', function () {
    expect(imageGateway()->callParseSize(null))->toEqual([1024, 1024]);
    expect(imageGateway()->callParseSize('1:1'))->toEqual([1024, 1024]);
});

test('stability body uses prompt and aspect ratio', function () {
    $body = imageGateway()->callPrepareBody('stability.sd3-5-large-v1:0', 'a cat', '2:3', 'standard');

    expect($body)->toEqual([
        'prompt' => 'a cat',
        'aspect_ratio' => '2:3',
        'output_format' => 'png',
    ]);
});

test('stability body omits aspect ratio when size is null', function () {
    $body = imageGateway()->callPrepareBody('stability.stable-image-ultra-v1:0', 'a cat', null, 'standard');

    expect($body)->toEqual([
        'prompt' => 'a cat',
        'output_format' => 'png',
    ]);
});

test('titan image body uses text to image params', function () {
    $body = imageGateway()->callPrepareBody('amazon.titan-image-generator-v1', 'a dog', '2:3', 'premium');

    expect($body)->toEqual([
        'taskType' => 'TEXT_IMAGE',
        'textToImageParams' => ['text' => 'a dog'],
        'imageGenerationConfig' => [
            'numberOfImages' => 1,
            'quality' => 'premium',
            'height' => 1152,
            'width' => 768,
            'cfgScale' => 7.0,
        ],
    ]);
});

test('titan image body defaults quality to standard', function () {
    $body = imageGateway()->callPrepareBody('amazon.titan-image-generator-v1', 'a dog', null, 'standard');

    expect($body['imageGenerationConfig']['quality'])->toBe('standard');
});

test('nova canvas body uses text to image params', function () {
    $body = imageGateway()->callPrepareBody('amazon.nova-canvas-v1:0', 'a bird', '3:2', 'standard');

    expect($body)->toEqual([
        'taskType' => 'TEXT_IMAGE',
        'textToImageParams' => ['text' => 'a bird'],
        'imageGenerationConfig' => [
            'numberOfImages' => 1,
            'quality' => 'standard',
            'width' => 1152,
            'height' => 768,
        ],
    ]);
});

test('unknown model family falls back to plain prompt body', function () {
    $body = imageGateway()->callPrepareBody('unknown-model', 'something', '1:1', 'high');

    expect($body)->toEqual(['prompt' => 'something']);
});

test('stability response is parsed from images array', function () {
    $images = imageGateway()->callParseResponse('stability.sd3-5-large-v1:0', [
        'images' => ['sd-image-1', 'sd-image-2'],
    ]);

    expect($images)->toHaveCount(2)
        ->and($images[0]->image)->toBe('sd-image-1')
        ->and($images[0]->mime)->toBe('image/png')
        ->and($images[1]->image)->toBe('sd-image-2');
});

test('titan response is parsed from images array', function () {
    $images = imageGateway()->callParseResponse('amazon.titan-image-generator-v1', [
        'images' => ['titan-image-1', 'titan-image-2'],
    ]);

    expect($images)->toHaveCount(2)
        ->and($images[0]->image)->toBe('titan-image-1')
        ->and($images[0]->mime)->toBe('image/png');
});

test('nova canvas response is parsed from images array', function () {
    $images = imageGateway()->callParseResponse('amazon.nova-canvas-v1:0', [
        'images' => ['nova-image-1'],
    ]);

    expect($images)->toHaveCount(1)
        ->and($images[0]->image)->toBe('nova-image-1');
});

test('unknown model family returns empty collection', function () {
    $images = imageGateway()->callParseResponse('unknown-model', [
        'artifacts' => [['base64' => 'ignored']],
        'images' => ['ignored'],
    ]);

    expect($images)->toHaveCount(0);
});

test('missing images key yields empty collection', function () {
    expect(imageGateway()->callParseResponse('stability.sd3-5-large-v1:0', [])->count())->toBe(0);
    expect(imageGateway()->callParseResponse('amazon.titan-image-v1', [])->count())->toBe(0);
    expect(imageGateway()->callParseResponse('amazon.nova-canvas-v1', [])->count())->toBe(0);
});
