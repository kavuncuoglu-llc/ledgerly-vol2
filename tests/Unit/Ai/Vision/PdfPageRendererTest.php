<?php

use App\Ai\Vision\PdfPageRenderer;
use App\Ai\Vision\PdfRenderingUnavailable;
use Symfony\Component\Process\ExecutableFinder;

function rendererWhoseImagickRefusesPdfs(): PdfPageRenderer
{
    return new class extends PdfPageRenderer
    {
        protected function withImagick(string $path): string
        {
            throw new ImagickException('attempt to perform an operation not allowed by the security policy `PDF\'');
        }
    };
}

it('falls back to pdftoppm when Imagick is loaded but refuses to read PDFs', function () {
    $png = rendererWhoseImagickRefusesPdfs()->firstPageAsPng(base_path('storage/samples/clean.pdf'));

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
})->skip(
    fn () => ! extension_loaded('imagick') || (new ExecutableFinder)->find('pdftoppm') === null,
    'needs the Imagick extension and pdftoppm'
);

it('says what to install when Imagick refuses PDFs and there is no pdftoppm', function () {
    $renderer = rendererWhoseImagickRefusesPdfs();

    expect(fn () => $renderer->firstPageAsPng(base_path('storage/samples/clean.pdf')))
        ->toThrow(PdfRenderingUnavailable::class, 'security policy');
})->skip(
    fn () => ! extension_loaded('imagick') || (new ExecutableFinder)->find('pdftoppm') !== null,
    'needs the Imagick extension and no pdftoppm'
);
