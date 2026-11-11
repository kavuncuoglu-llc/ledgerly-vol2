<?php

namespace App\Ai\Vision;

use Illuminate\Support\Facades\Process;
use Imagick;
use ImagickException;
use Symfony\Component\Process\ExecutableFinder;

// Renders page one of a PDF to a PNG: Imagick when the extension is loaded and allowed to read PDFs, Poppler's pdftoppm otherwise
class PdfPageRenderer
{
    public function firstPageAsPng(string $path): string
    {
        $imagickFailure = null;

        if (extension_loaded('imagick')) {
            try {
                return $this->withImagick($path);
            } catch (ImagickException $e) {
                $imagickFailure = $e->getMessage();
            }
        }

        $pdftoppm = (new ExecutableFinder)->find('pdftoppm');

        if ($pdftoppm) {
            return $this->withPdftoppm($pdftoppm, $path);
        }

        if ($imagickFailure) {
            throw new PdfRenderingUnavailable('Imagick could not read the PDF ('.$imagickFailure.'). ImageMagick often blocks PDFs in its policy.xml or lacks Ghostscript; install Poppler instead (brew install poppler, apt install poppler-utils).');
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

    protected function withImagick(string $path): string
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
