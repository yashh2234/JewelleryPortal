<?php

namespace App\Support;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class Qr
{
    /** PNG data URI, usable in dompdf and HTML. */
    public static function dataUri(string $text, int $scale = 4): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'scale' => $scale,
            'outputBase64' => true,
            'quietzoneSize' => 1,
        ]);

        return (new QRCode($options))->render($text);
    }
}
