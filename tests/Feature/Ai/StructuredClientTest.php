<?php

use App\Ai\Schema\Describe;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Structured\ExtractionMeta;
use App\Ai\Structured\StructuredClient;
use App\Ai\Structured\StructuredOutputFailed;
use App\Ai\Structured\StructuredRefusal;
use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final readonly class RefusableData
{
    public function __construct(
        #[Describe('A name')] public ?string $name,
        #[Describe('Why extraction is impossible; null otherwise')] public ?string $error,
        public ?ExtractionMeta $meta = null,
    ) {}

    public static function fromArray(array $d, ?ExtractionMeta $meta = null): self
    {
        return new self($d['name'] ?? null, $d['error'] ?? null, $meta);
    }

    public static function rules(): array
    {
        return ['name' => ['nullable', 'string', 'max:5'], 'error' => ['nullable', 'string']];
    }

    public static function afterRules(): array
    {
        return [];
    }
}

function badTotal(): string
{
    return json_encode(northgateInvoice(['total' => 1204.50]));
}

function goodInvoice(): string
{
    return json_encode(northgateInvoice());
}

function extractInvoice(array $options = []): InvoiceData
{
    return app(StructuredClient::class)->extract(InvoiceData::class, [['role' => 'user', 'content' => 'read this']], $options, 'invoice.extract');
}

it('returns a typed object when the first answer is valid', function () {
    Http::fake(['*/chat/completions' => chatReply(goodInvoice())]);

    $invoice = extractInvoice();

    expect($invoice)->toBeInstanceOf(InvoiceData::class)
        ->and($invoice->total)->toBe(1284.5)
        ->and($invoice->meta->repaired)->toBeFalse()
        ->and($invoice->meta->attempts)->toBe(1);

    Http::assertSentCount(1);
});

it('sends the schema from the DTO as strict response_format with the deterministic profile', function () {
    Http::fake(['*/chat/completions' => chatReply(goodInvoice())]);

    extractInvoice(['model' => 'llama3.2-vision:11b']);

    Http::assertSent(function (Request $request) {
        $format = $request['response_format'];

        return $format['type'] === 'json_schema'
            && $format['json_schema']['name'] === 'invoice_data'
            && $format['json_schema']['strict'] === true
            && $format['json_schema']['schema']['additionalProperties'] === false
            && in_array('line_items', $format['json_schema']['schema']['required'], true)
            && $request['model'] === 'llama3.2-vision:11b'
            && $request['temperature'] === 0
            && $request['seed'] === 42
            && $request['stream'] === false;
    });
});

it('repairs once: sends the invalid output and the validator messages back', function () {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push(chatBody(badTotal()))
        ->push(chatBody(goodInvoice()))]);

    $invoice = extractInvoice();

    expect($invoice->total)->toBe(1284.5)
        ->and($invoice->meta->repaired)->toBeTrue()
        ->and($invoice->meta->attempts)->toBe(2);

    $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data());

    $second = $requests[1]['messages'];

    expect($second)->toHaveCount(3)
        ->and($second[0])->toBe(['role' => 'user', 'content' => 'read this'])
        ->and($second[1])->toBe(['role' => 'assistant', 'content' => badTotal()])
        ->and($second[2]['role'])->toBe('user')
        ->and($second[2]['content'])->toContain('The object you returned has validation errors:')
        ->and($second[2]['content'])->toContain('Total does not equal subtotal plus tax.')
        ->and($second[2]['content'])->toContain('Change only what is needed')
        ->and($requests[1]['response_format'])->toBe($requests[0]['response_format']);
});

it('logs every attempt, and marks only the repair', function () {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push(chatBody(badTotal()))
        ->push(chatBody(goodInvoice()))]);

    extractInvoice(['prompt_version' => '2026-11-01.1']);

    $calls = ModelCall::orderBy('id')->get();

    expect($calls)->toHaveCount(2)
        ->and($calls->pluck('feature')->all())->toBe(['invoice.extract', 'invoice.extract'])
        ->and($calls->pluck('repaired')->all())->toBe([false, true])
        ->and($calls->pluck('prompt_version')->all())->toBe(['2026-11-01.1', '2026-11-01.1']);
});

it('does not leak the bookkeeping options into the request body', function () {
    Http::fake(['*/chat/completions' => chatReply(goodInvoice())]);

    extractInvoice(['prompt_version' => 'v1']);

    Http::assertSent(fn (Request $request) => ! isset($request['repaired']) && ! isset($request['prompt_version']));
});

it('raises StructuredOutputFailed after exactly one repair, with both attempts', function () {
    Http::fake(['*/chat/completions' => chatReply(badTotal())]);

    try {
        extractInvoice();
    } catch (StructuredOutputFailed $e) {
        expect($e->attempts)->toHaveCount(2)
            ->and($e->class)->toBe(InvoiceData::class)
            ->and($e->errors->first('total'))->toBe('Total does not equal subtotal plus tax.')
            ->and($e->getMessage())->toContain('InvoiceData');

        Http::assertSentCount(2);

        return;
    }

    $this->fail('StructuredOutputFailed was not thrown');
});

it('skips the repair when the model declines through the error field', function () {
    Http::fake(['*/chat/completions' => chatReply(json_encode(['name' => null, 'error' => 'image too blurry']))]);

    try {
        app(StructuredClient::class)->extract(RefusableData::class, [['role' => 'user', 'content' => 'x']]);
    } catch (StructuredRefusal $e) {
        expect($e->reason)->toBe('image too blurry')
            ->and($e->attempts)->toHaveCount(1);

        Http::assertSentCount(1);

        return;
    }

    $this->fail('StructuredRefusal was not thrown');
});

it('treats a reply that does not parse as a configuration bug, not a retry', function () {
    Http::fake(['*/chat/completions' => chatReply('Here is the data: {"total": 1}')]);

    extractInvoice();
})->throws(JsonException::class);

it('treats JSON that is not an object as a configuration bug too', function () {
    Http::fake(['*/chat/completions' => chatReply('"hello"')]);

    extractInvoice();
})->throws(JsonException::class);

it('uses json_object mode at level two', function () {
    config(['ai.structured_level' => 'json']);

    Http::fake(['*/chat/completions' => chatReply(goodInvoice())]);

    extractInvoice();

    Http::assertSent(fn (Request $request) => $request['response_format'] === ['type' => 'json_object']);
});

it('sends no response_format at level one', function () {
    config(['ai.structured_level' => 'ask']);

    Http::fake(['*/chat/completions' => chatReply(goodInvoice())]);

    extractInvoice();

    Http::assertSent(fn (Request $request) => ! isset($request['response_format']));
});

it('lets the caller override response_format and the profile', function () {
    Http::fake(['*/chat/completions' => chatReply(goodInvoice())]);

    extractInvoice(['profile' => 'grounded', 'response_format' => ['type' => 'json_object']]);

    Http::assertSent(fn (Request $request) => $request['response_format'] === ['type' => 'json_object'] && $request['temperature'] === 0.2);
});
