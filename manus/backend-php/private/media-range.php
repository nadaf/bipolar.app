<?php
declare(strict_types=1);
/** Determina um único intervalo HTTP, sem aceitar intervalos múltiplos. */
function bipolar_media_range(string $header, int $length): ?array
{
    if ($header === '') return null;
    if ($length < 1 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match) || ($match[1] === '' && $match[2] === '')) {
        throw new BipolarApiException('invalid_range', 416);
    }
    if ($match[1] === '') {
        $suffix = (int) $match[2];
        if ($suffix < 1) throw new BipolarApiException('invalid_range', 416);
        $start = max(0, $length - $suffix);
        $end = $length - 1;
    } else {
        $start = (int) $match[1];
        $end = $match[2] === '' ? $length - 1 : min($length - 1, (int) $match[2]);
        if ($start >= $length || $start > $end) throw new BipolarApiException('invalid_range', 416);
    }
    return [$start, $end];
}
