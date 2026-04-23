<?php

use Laravel\Ai\Gateway\Bedrock\BedrockImageGateway;

function imageGateway(): object
{
    return new class extends BedrockImageGateway
    {
        public function callPrepareBody(string $model, string $prompt, ?string $size, ?string $quality): array
        {
            return $this->prepareImageRequestBody($model, $prompt, $size, $quality);
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

test('stability model body uses text prompts and steps', function () {
    $body = imageGateway()->callPrepareBody('stability.sd3-large', 'a cat', '1:1', 'high');

    expect($body)->toEqual([
        'text_prompts' => [['text' => 'a cat', 'weight' => 1.0]],
        'cfg_scale' => 7.0,
        'steps' => 50,
        'width' => 1024,
        'height' => 1024,
    ]);
});

test('stability model body uses 30 steps for non-high quality', function () {
    $body = imageGateway()->callPrepareBody('stability.sd3-large', 'a cat', '1:1', 'standard');

    expect($body['steps'])->toBe(30);
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
    $body = imageGateway()->callPrepareBody('amazon.titan-image-generator-v1', 'a dog', null, null);

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

test('stability response is parsed from artifacts', function () {
    $images = imageGateway()->callParseResponse('stability.sd3-large', [
        'artifacts' => [
            ['base64' => 'imgdata-1'],
            ['base64' => 'imgdata-2'],
        ],
    ]);

    expect($images)->toHaveCount(2)
        ->and($images[0]->image)->toBe('imgdata-1')
        ->and($images[0]->mime)->toBe('image/png')
        ->and($images[1]->image)->toBe('imgdata-2');
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

test('missing artifacts or images key yields empty collection', function () {
    expect(imageGateway()->callParseResponse('stability.sd3', [])->count())->toBe(0);
    expect(imageGateway()->callParseResponse('amazon.titan-image-v1', [])->count())->toBe(0);
    expect(imageGateway()->callParseResponse('amazon.nova-canvas-v1', [])->count())->toBe(0);
});
