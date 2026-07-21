<?php

use Laravel\Ai\Files\Base64Document;

test('base64 document rejects empty content', function () {
    new Base64Document('');
})->throws(InvalidArgumentException::class, 'Base64 document content cannot be empty.');
