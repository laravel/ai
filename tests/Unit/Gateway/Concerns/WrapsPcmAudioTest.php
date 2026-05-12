<?php

use Laravel\Ai\Gateway\Concerns\WrapsPcmAudio;

beforeEach(function () {
    $this->trait = new class
    {
        use WrapsPcmAudio {
            pcmToWav as public;
        }
    };
});

test('wav header begins with RIFF/WAVE container and includes fmt and data chunks', function () {
    $pcm = str_repeat("\x00", 64);

    $wav = $this->trait->pcmToWav($pcm);

    expect(substr($wav, 0, 4))->toBe('RIFF')
        ->and(substr($wav, 8, 4))->toBe('WAVE')
        ->and(substr($wav, 12, 4))->toBe('fmt ')
        ->and(substr($wav, 36, 4))->toBe('data')
        ->and(substr($wav, 44))->toBe($pcm);
});

test('wav header reports the correct riff and data chunk sizes', function () {
    $pcm = str_repeat("\xAB", 100);

    $wav = $this->trait->pcmToWav($pcm);

    $riffSize = unpack('V', substr($wav, 4, 4))[1];
    $dataSize = unpack('V', substr($wav, 40, 4))[1];

    expect($riffSize)->toBe(36 + strlen($pcm))
        ->and($dataSize)->toBe(strlen($pcm));
});

test('wav header encodes pcm format defaults (mono 16-bit at 24kHz)', function () {
    $wav = $this->trait->pcmToWav("\x00\x00");

    $fmt = unpack('Vchunk/vformat/vchannels/Vrate/Vbyte/valign/vbits', substr($wav, 16, 20));

    expect($fmt['chunk'])->toBe(16)
        ->and($fmt['format'])->toBe(1)
        ->and($fmt['channels'])->toBe(1)
        ->and($fmt['rate'])->toBe(24000)
        ->and($fmt['bits'])->toBe(16)
        ->and($fmt['byte'])->toBe(48000)
        ->and($fmt['align'])->toBe(2);
});

test('wav header honors custom sample rate, channels, and bit depth', function () {
    $wav = $this->trait->pcmToWav("\x00\x00", sampleRate: 44100, channels: 2, bitsPerSample: 24);

    $fmt = unpack('Vchunk/vformat/vchannels/Vrate/Vbyte/valign/vbits', substr($wav, 16, 20));

    expect($fmt['channels'])->toBe(2)
        ->and($fmt['rate'])->toBe(44100)
        ->and($fmt['bits'])->toBe(24)
        ->and($fmt['byte'])->toBe(44100 * 2 * 24 / 8)
        ->and($fmt['align'])->toBe(2 * 24 / 8);
});
