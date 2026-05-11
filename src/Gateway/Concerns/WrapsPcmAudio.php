<?php

namespace Laravel\Ai\Gateway\Concerns;

trait WrapsPcmAudio
{
    protected function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataSize = strlen($pcm);
        $byteRate = intdiv($sampleRate * $channels * $bitsPerSample, 8);
        $blockAlign = intdiv($channels * $bitsPerSample, 8);

        return 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            .'data'
            .pack('V', $dataSize)
            .$pcm;
    }
}
