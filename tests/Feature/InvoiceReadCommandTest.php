<?php

use App\Ai\Prompts\InvoicePrompt;
use App\Ai\Vision\ImageEncoder;
use App\Ai\Vision\PdfPageRenderer;
use App\Ai\Vision\PdfRenderingUnavailable;
use App\Models\ModelCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function fakeVisionReplies(array ...$invoices): void
{
    $sequence = Http::sequence();

    foreach ($invoices as $invoice) {
        $sequence->push(chatBody(json_encode($invoice), usage: ['prompt_tokens' => 742, 'completion_tokens' => 118], model: 'llama3.2-vision:11b'));
    }

    Http::fake(['*/chat/completions' => $sequence]);
}

function firstSentBody(): array
{
    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent) {
        $sent ??= $request->data();

        return true;
    });

    return $sent;
}

it('sends the image as a base64 data URL in the standard chat format', function () {
    fakeVisionReplies(northgateInvoice());

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/bad-scan.jpg')])->assertSuccessful();

    $body = firstSentBody();
    $user = $body['messages'][1];

    expect($body['model'])->toBe('llama3.2-vision:11b')
        ->and($body['messages'][0])->toBe(['role' => 'system', 'content' => InvoicePrompt::system()])
        ->and($user['role'])->toBe('user')
        ->and($user['content'][0])->toBe(['type' => 'text', 'text' => InvoicePrompt::extract()])
        ->and($user['content'][1]['type'])->toBe('image_url')
        ->and($user['content'][1]['image_url']['url'])->toStartWith('data:image/jpeg;base64,')
        ->and($body['stream'])->toBeFalse();
});

it('uses the deterministic profile, the configured vision model and an InvoiceData schema', function () {
    fakeVisionReplies(northgateInvoice());
    config(['ai.vision_model' => 'moondream']);

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/photo.jpg')])->assertSuccessful();

    $body = firstSentBody();

    expect($body['model'])->toBe('moondream')
        ->and($body['temperature'])->toBe(0)
        ->and($body['seed'])->toBe(42)
        ->and($body['top_p'])->toBe(1.0)
        ->and($body['response_format']['json_schema']['name'])->toBe('invoice_data')
        ->and($body['response_format']['json_schema']['strict'])->toBeTrue();
});

it('resizes the upload to about one megapixel before it leaves the machine', function () {
    fakeVisionReplies(northgateInvoice());

    $big = tempnam(sys_get_temp_dir(), 'big').'.png';
    $canvas = imagecreatetruecolor(3000, 2000);
    imagepng($canvas, $big);

    $dataUrl = app(ImageEncoder::class)->dataUrl($big);

    $bytes = base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,')));
    [$width, $height] = getimagesizefromstring($bytes);

    unlink($big);

    expect($width)->toBe(1024)
        ->and($height)->toBe(683)
        ->and($dataUrl)->toStartWith('data:image/jpeg;base64,');
});

it('never enlarges a small image', function () {
    $small = tempnam(sys_get_temp_dir(), 'small').'.png';
    imagepng(imagecreatetruecolor(400, 300), $small);

    $bytes = base64_decode(substr(app(ImageEncoder::class)->dataUrl($small), strlen('data:image/jpeg;base64,')));

    unlink($small);

    expect(getimagesizefromstring($bytes)[0])->toBe(400);
});

it('prints the fields and says it was not repaired', function () {
    fakeVisionReplies(northgateInvoice());

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/photo.jpg')])
        ->expectsOutputToContain('Northgate Paper Co')
        ->expectsOutputToContain('NP-20931')
        ->expectsOutputToContain('2026-04-15')
        ->expectsOutputToContain('1,284.50')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('reads the bad scan by repairing the misread total once', function () {
    fakeVisionReplies(northgateInvoice(['total' => 1204.50]), northgateInvoice());

    $exitCode = Artisan::call('invoice:read', ['path' => base_path('storage/samples/bad-scan.jpg')]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('| total     | 1,284.50')
        ->and($output)->toContain('| repaired  | yes')
        ->and(ModelCall::orderBy('id')->pluck('repaired')->all())->toBe([false, true]);

    Http::assertSentCount(2);
});

it('says a clean read was not repaired', function () {
    fakeVisionReplies(northgateInvoice());

    $output = Artisan::call('invoice:read', ['path' => base_path('storage/samples/photo.jpg')]) === 0
        ? Artisan::output()
        : '';

    expect($output)->toContain('| repaired  | no');
});

it('hands the invoice to a person when the repair does not fix it', function () {
    fakeVisionReplies(northgateInvoice(['total' => 1204.50]), northgateInvoice(['total' => 1204.50]));

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/bad-scan.jpg')])
        ->expectsOutputToContain('Needs a person')
        ->assertFailed();

    Http::assertSentCount(2);
});

it('shows a dash for a total the model could not read', function () {
    fakeVisionReplies(northgateInvoice(['total' => null, 'tax' => null]));

    $output = Artisan::call('invoice:read', ['path' => base_path('storage/samples/bad-scan.jpg')]) === 0
        ? Artisan::output()
        : '';

    expect($output)->toContain('| total     | —');
});

it('explains a server that ignores the schema', function () {
    Http::fake(['*/chat/completions' => chatReply('I cannot read this image.')]);

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/bad-scan.jpg')])
        ->expectsOutputToContain('did not enforce the schema')
        ->assertFailed();
});

it('logs the calls in model_calls under invoice.extract with the prompt version', function () {
    fakeVisionReplies(northgateInvoice());

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/photo.jpg')])->assertSuccessful();

    $call = ModelCall::sole();

    expect($call->feature)->toBe('invoice.extract')
        ->and($call->provider)->toBe('vision')
        ->and($call->model)->toBe('llama3.2-vision:11b')
        ->and($call->prompt_tokens)->toBe(742)
        ->and($call->completion_tokens)->toBe(118)
        ->and($call->finish_reason)->toBe('stop')
        ->and($call->prompt_version)->toBe(InvoicePrompt::VERSION)
        ->and($call->repaired)->toBeFalse();
});

it('renders page one of a PDF to an image before sending it', function () {
    fakeVisionReplies(northgateInvoice());

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/clean.pdf')])
        ->expectsOutputToContain('Northgate Paper Co')
        ->assertSuccessful();

    $url = firstSentBody()['messages'][1]['content'][1]['image_url']['url'];
    [$width, $height] = getimagesizefromstring(base64_decode(substr($url, strlen('data:image/jpeg;base64,'))));

    expect(max($width, $height))->toBe(1024);
})->skip(fn () => ! (new PdfPageRenderer)->isAvailable(), 'needs the Imagick extension or pdftoppm');

it('says what is missing when a PDF cannot be rendered', function () {
    $this->app->bind(PdfPageRenderer::class, fn () => new class extends PdfPageRenderer
    {
        public function firstPageAsPng(string $path): string
        {
            throw new PdfRenderingUnavailable('Rendering a PDF needs the Imagick PHP extension or Poppler');
        }
    });

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/clean.pdf')])
        ->expectsOutputToContain('needs the Imagick PHP extension or Poppler')
        ->assertFailed();
});

it('fails on a path that is not a file', function () {
    $this->artisan('invoice:read', ['path' => base_path('storage/samples/missing.jpg')])
        ->expectsOutputToContain('No such file')
        ->assertFailed();
});

it('explains a model server that is down', function () {
    Sleep::fake();

    Http::fake(['*/chat/completions' => fn () => throw new ConnectionException('refused')]);

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/photo.jpg')])
        ->expectsOutputToContain('Could not reach the model server')
        ->assertFailed();
});

it('explains a vision model that is not pulled', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => 'model not found'], 404)]);

    $this->artisan('invoice:read', ['path' => base_path('storage/samples/photo.jpg')])
        ->expectsOutputToContain('ollama pull llama3.2-vision:11b')
        ->assertFailed();
});

it('has the three sample invoices the chapter runs', function () {
    foreach (['clean.pdf', 'photo.jpg', 'bad-scan.jpg'] as $file) {
        expect(is_file(base_path("storage/samples/{$file}")))->toBeTrue($file);
    }
});
