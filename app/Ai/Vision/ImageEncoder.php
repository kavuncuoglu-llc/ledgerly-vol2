<?php

namespace App\Ai\Vision;

use Intervention\Image\Laravel\Facades\Image;

// An image or the first page of a PDF, resized to about one megapixel, as the data URL a vision model reads
final class ImageEncoder
{
    public function __construct(private PdfPageRenderer $pdf) {}

    public function dataUrl(string $path): string
    {
        $source = $this->isPdf($path)
            ? $this->pdf->firstPageAsPng($path)
            : $path;

        $image = Image::read($source)->scaleDown(1024, 1024)->toJpeg(85);   // ~700 tokens

        return 'data:image/jpeg;base64,'.base64_encode((string) $image);
    }

    private function isPdf(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }
}
