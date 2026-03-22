<?php

declare(strict_types=1);

namespace OpenILink;

final class Voice
{
    public const DEFAULT_SAMPLE_RATE = 24000;

    public static function buildWav(string $pcm, int $sampleRate, int $numChannels, int $bitsPerSample): string
    {
        $dataSize = strlen($pcm);
        $byteRate = (int) (($sampleRate * $numChannels * $bitsPerSample) / 8);
        $blockAlign = (int) (($numChannels * $bitsPerSample) / 8);

        return 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVE'
            . 'fmt '
            . pack('VvvVVvv', 16, 1, $numChannels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            . 'data'
            . pack('V', $dataSize)
            . $pcm;
    }
}
