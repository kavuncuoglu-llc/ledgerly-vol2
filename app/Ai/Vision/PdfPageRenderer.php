<?php

namespace App\Ai\Vision;

use Illuminate\Support\Facades\Process;
use Imagick;
use Symfony\Component\Process\ExecutableFinder;

// Renders page one of a PDF to a PNG: Imagick when the extension is loaded, Poppler's pdftoppm otherwise
class PdfPageRenderer
{
    public function firstPageAsPng(string $path): string
    {
        if (extension_loaded('imagick')) {
            return $this->withImagick($path);
        }

        $pdftoppm = (new ExecutableFinder)->find('pdftoppm');

        if ($pdftoppm) {
            return $this->withPdftoppm($pdftoppm, $path);
        }

        throw new PdfRenderingUnavailable('Rendering a PDF needs the Imagick PHP extension or Poppler (brew install poppler, apt install poppler-utils).');
    }

    public function isAvailable(): bool
    {
        if (extension_loaded('imagick')) {
            return true;
        }

        return (new ExecutableFinder)->find('pdftoppm') !== null;
    }

    private function withImagick(string $path): string
    {
        $page = new Imagick;
        $page->setResolution(150, 150);
        $page->readImage($path.'[0]');
        $page->setImageBackgroundColor('white');
        $page->setImageFormat('png');

        return $page->getImageBlob();
    }

    private function withPdftoppm(string $pdftoppm, string $path): string
    {
        $base = tempnam(sys_get_temp_dir(), 'ledgerly-pdf-');

        $result = Process::run([$pdftoppm, '-png', '-r', '150', '-f', '1', '-l', '1', '-singlefile', $path, $base]);

        $png = $base.'.png';

        if ($result->failed() || ! is_file($png)) {
            @unlink($base);

            throw new PdfRenderingUnavailable('pdftoppm could not render '.$path.': '.trim($result->errorOutput()));
        }

        $bytes = file_get_contents($png);

        unlink($png);
        unlink($base);

        return $bytes;
    }
}
