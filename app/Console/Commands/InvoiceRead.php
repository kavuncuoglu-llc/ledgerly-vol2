<?php

namespace App\Console\Commands;

use App\Ai\Prompts\InvoicePrompt;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Structured\StructuredClient;
use App\Ai\Structured\StructuredOutputFailed;
use App\Ai\Structured\StructuredRefusal;
use App\Ai\Vision\ImageEncoder;
use App\Ai\Vision\PdfRenderingUnavailable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use JsonException;

class InvoiceRead extends Command
{
    protected $signature = 'invoice:read {path : An invoice image (jpg, png, webp) or a PDF}';

    protected $description = 'Read a supplier invoice with a vision model into a typed, validated InvoiceData';

    public function handle(StructuredClient $structured, ImageEncoder $images): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        try {
            $invoice = $structured->extract(
                InvoiceData::class,
                InvoicePrompt::messages($images->dataUrl($path)),
                ['model' => config('ai.vision_model'), 'prompt_version' => InvoicePrompt::VERSION],
                feature: 'invoice.extract',
            );
        } catch (PdfRenderingUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->error('The model server answered '.$e->response->status().'. Is '.config('ai.vision_model').' pulled? (ollama pull '.config('ai.vision_model').')');

            return self::FAILURE;
        } catch (StructuredOutputFailed $e) {
            $this->error('Needs a person: '.$e->getMessage());

            return self::FAILURE;
        } catch (StructuredRefusal $e) {
            $this->error('Needs a person: '.$e->getMessage());

            return self::FAILURE;
        } catch (JsonException $e) {
            $this->error('The model server did not enforce the schema ('.$e->getMessage().'). Check AI_STRUCTURED_LEVEL and that the model supports response_format.');

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['vendor', $invoice->vendor_name ?? '—'],
            ['invoice #', $invoice->invoice_number ?? '—'],
            ['due', $invoice->due_date ?? '—'],
            ['total', $invoice->total !== null ? number_format($invoice->total, 2) : '—'],
            ['lines', count($invoice->line_items)],
            ['repaired', $invoice->meta?->repaired ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
