<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Python\Streams;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Security\AnswerRenderer;
use App\Ai\Streaming\ReplyStarter;
use App\Ai\Tools\PendingAction;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\HelpChunk;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\PendingToolCall;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStreams;
use Tests\TestCase;

require_once __DIR__.'/Support/AskAssistant.php';
require_once __DIR__.'/Support/FakeTools.php';
require_once __DIR__.'/Support/Modelfile.php';
require_once __DIR__.'/Support/FakeOllamaServer.php';
require_once __DIR__.'/Support/FakeStreams.php';
require_once __DIR__.'/Support/FakeLlamaServer.php';
require_once __DIR__.'/Support/FakeVllmServer.php';
require_once __DIR__.'/Support/FakeTritonServer.php';
require_once __DIR__.'/Support/Pbtxt.php';
require_once __DIR__.'/Support/EnvExample.php';
require_once __DIR__.'/Support/TriageWorld.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// Chapter 32: every customer-written turn reaches the model inside a random-token wrapper; a fake server that looks the text up by value needs it back.
function unwrapped(string $content): string
{
    return trim((string) preg_replace('/^<untrusted-[0-9a-f]+[^>]*>\n?|\n?<\/untrusted-[0-9a-f]+>$/', '', trim($content)));
}

// Chapter 32: the whole answer path for one question, as a queued reply with the sync queue, and what the page would show of it.
function runAnswerQuestion(string $question, User $as): Message
{
    test()->actingAs($as);

    $reply = app(ReplyStarter::class)->start($as, $question);

    return Message::withoutGlobalScopes()->findOrFail($reply->id);
}

function render_answer(Message $message): string
{
    return app(AnswerRenderer::class)->render((string) $message->content);
}

// Chapter 33, Topic 6: the confirmation token belongs to the approver's page; a test plays the approver and asks for it the way the page does.
function approvalToken(PendingToolCall $pending): string
{
    return PendingAction::tokenFor($pending->fresh());
}

function fakeStreams(): FakeStreams
{
    $streams = new FakeStreams;

    app()->instance(Streams::class, $streams);

    return $streams;
}

function helpLibrary(array $files): string
{
    $directory = sys_get_temp_dir().'/ledgerly-help-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        $target = $directory.'/'.$path;

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        file_put_contents($target, $contents);
    }

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    register_shutdown_function(function () use ($directory) {
        removeDirectory($directory);
    });

    config(['ledgerly.help_path' => $directory]);

    return $directory;
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        is_dir($path)
            ? removeDirectory($path)
            : unlink($path);
    }

    rmdir($directory);
}

function helpArticleSource(string $title, string $category, string $body = "Body text.\n\n## First section\n\nDetails.\n", string $summary = 'A short summary.', string $updated = '2025-10-02'): string
{
    return "---\ntitle: {$title}\ncategory: {$category}\nsummary: {$summary}\nupdated: {$updated}\n---\n\n{$body}";
}

function fakeEmbeddingVector(string $text, int $dimensions = 768): array
{
    $text = preg_replace('/^search_(query|document): /', '', $text);

    $vector = array_fill(0, $dimensions, 0.0);

    foreach (preg_split('/\W+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $vector[crc32($word) % $dimensions] += 1.0;
    }

    $length = sqrt(array_sum(array_map(fn (float $value) => $value * $value, $vector)));

    if ($length === 0.0) {
        $vector[0] = 1.0;
        $length = 1.0;
    }

    return array_map(fn (float $value) => round($value / $length, 6), $vector);
}

function embeddingsResponse(Request $request, int $dimensions = 768): PromiseInterface
{
    $inputs = (array) $request['input'];                                         // one string, or the array Embedder::documents() sends

    return Http::response([
        'object' => 'list',
        'data' => array_map(fn (string $input, int $index) => ['object' => 'embedding', 'index' => $index, 'embedding' => fakeEmbeddingVector($input, $dimensions)], $inputs, array_keys($inputs)),
    ]);
}

function flatEmbeddings(float $value, int $dimensions = 768): Closure
{
    return fn (Request $request) => Http::response(['data' => array_map(fn () => ['embedding' => array_fill(0, $dimensions, $value)], (array) $request['input'])]);   // one identical vector per passage sent
}

function fakeEmbeddings(int $dimensions = 768): void
{
    Http::fake([
        '*/embeddings' => fn (Request $request) => embeddingsResponse($request, $dimensions),
    ]);
}

function chatBody(string $content, string $finish = 'stop', array $usage = ['prompt_tokens' => 190, 'completion_tokens' => 24], string $model = 'llama3.1:8b'): array
{
    return [
        'id' => 'chatcmpl-'.bin2hex(random_bytes(3)),
        'model' => $model,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]],
        'usage' => $usage,
    ];
}

function chatReply(string $content, string $finish = 'stop', array $usage = ['prompt_tokens' => 190, 'completion_tokens' => 24], string $model = 'llama3.1:8b'): PromiseInterface
{
    return Http::response(chatBody($content, $finish, $usage, $model));
}

function ticketSamples(): array
{
    return json_decode(file_get_contents(base_path('storage/samples/tickets.json')), true);
}

function ticketJson(string $category, array $fields = []): string
{
    return json_encode($fields + [
        'category' => $category,
        'invoice_number' => null,
        'customer_email' => null,
        'amount_mentioned' => null,
        'date_mentioned' => null,
    ]);
}

function northgateInvoice(array $override = []): array
{
    return $override + [
        'vendor_name' => 'Northgate Paper Co',
        'invoice_number' => 'NP-20931',
        'invoice_date' => '2026-03-16',
        'due_date' => '2026-04-15',
        'currency' => 'USD',
        'line_items' => [
            ['description' => 'Copy paper A4, 500 sheets', 'quantity' => 40, 'unit_price' => 12.50, 'amount' => 500.00],
            ['description' => 'Recycled envelopes DL', 'quantity' => 6, 'unit_price' => 24.90, 'amount' => 149.40],
            ['description' => 'Archive boxes', 'quantity' => 25, 'unit_price' => 8.50, 'amount' => 212.50],
            ['description' => 'Label rolls', 'quantity' => 12, 'unit_price' => 15.00, 'amount' => 180.00],
            ['description' => 'Toner cartridge', 'quantity' => 2, 'unit_price' => 121.30, 'amount' => 242.60],
        ],
        'subtotal' => 1284.50,
        'tax' => 0,
        'total' => 1284.50,
    ];
}

function toolCallsBody(array $calls, ?string $content = null, array $usage = ['prompt_tokens' => 310, 'completion_tokens' => 31]): array
{
    return [
        'id' => 'chatcmpl-'.bin2hex(random_bytes(3)),
        'model' => 'llama3.1:8b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => array_map(fn (array $call) => [
                    'id' => $call[0],
                    'type' => 'function',
                    'function' => ['name' => $call[1], 'arguments' => is_string($call[2]) ? $call[2] : json_encode($call[2])],
                ], $calls),
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => $usage,
    ];
}

function fakeChatSequence(array ...$bodies): void
{
    $sequence = Http::sequence();

    foreach ($bodies as $body) {
        $sequence->push($body);
    }

    Http::fake(['*/chat/completions' => $sequence]);
}

function sentChatBodies(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/chat/completions'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

function bindTools(Tool ...$tools): void
{
    $registry = new ToolRegistry;

    foreach ($tools as $tool) {
        $registry->add($tool);
    }

    app()->instance(ToolRegistry::class, $registry);
}

function customerWithInvoice(string $number = 'INV-4471', string $role = 'owner'): array
{
    $customer = Customer::factory()->create();

    $user = User::factory()->owner($customer)->create(['role' => $role]);

    $invoice = Invoice::factory()->for($customer)->overdue(18)->withLateFee()->create(['number' => $number, 'subtotal' => 1200, 'tax' => 84.50, 'total' => 1284.50]);

    return [$user, $invoice, $customer];
}

function toolMessages(array $body): array
{
    return array_values(array_filter($body['messages'], fn (array $m) => $m['role'] === 'tool'));
}

function conversationFor(User $user, string $status = 'open'): Conversation
{
    return Conversation::create([
        'user_id' => $user->id,
        'tenant_id' => $user->tenant_id,
        'status' => $status,
        'prompt_version' => SupportPrompt::VERSION,
        'summary_through_seq' => 0,
    ]);
}

function conversationWithMessages(Tenant $tenant, array $contents): Conversation
{
    $user = User::factory()->inTenant($tenant)->create();

    $conversation = conversationFor($user);

    $store = app(ConversationStore::class);

    foreach ($contents as $content) {
        $store->append($conversation, ['role' => 'user', 'content' => $content]);
        $store->append($conversation, ['role' => 'assistant', 'content' => "Noted: {$content}"]);
    }

    return $conversation;
}

function actingAsTenant(Tenant $tenant): void
{
    app(CurrentTenant::class)->set($tenant->id);
}

function addExchange(Conversation $conversation, string $question, string $answer, ?array $tool = null): void
{
    $store = app(ConversationStore::class);

    $store->append($conversation, ['role' => 'user', 'content' => $question]);

    if ($tool !== null) {
        [$id, $name, $arguments, $result] = $tool;

        $store->append($conversation, ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]]]]);
        $store->append($conversation, ['role' => 'tool', 'tool_call_id' => $id, 'content' => $result]);
    }

    $store->append($conversation, ['role' => 'assistant', 'content' => $answer]);
}

function angledVector(float $degrees, int $dimensions = 768): array
{
    $vector = array_fill(0, $dimensions, 0.0);

    $vector[0] = round(cos(deg2rad($degrees)), 8);
    $vector[1] = round(sin(deg2rad($degrees)), 8);

    return $vector;
}

function fakeEmbeddingAngles(array $anglesByText, float $otherwise = 270.0): Closure
{
    return function (Request $request) use ($anglesByText, $otherwise) {
        $text = preg_replace('/^search_(query|document): /', '', $request['input']);

        return Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => angledVector($anglesByText[$text] ?? $otherwise)]],
        ]);
    };
}

function fakeCacheWorld(array $anglesByText, array $chatBodies = []): void
{
    $sequence = Http::sequence();

    foreach ($chatBodies as $body) {
        $sequence->push($body);
    }

    Http::fake([
        '*/embeddings' => fakeEmbeddingAngles($anglesByText),
        '*/chat/completions' => $sequence,
    ]);
}

function classifierReply(string $category = 'howto'): array
{
    return chatBody(ticketJson($category));
}

function retrievedChunk(string $body = 'Fees start after a 30 day grace period.', string $source = 'help/billing/late-fees.md', string $heading = 'Late fees › How the fee is calculated', array $overrides = []): RetrievedChunk
{
    return new RetrievedChunk(...array_merge([
        'id' => 1,
        'source' => $source,
        'title' => 'Late fees',
        'headingPath' => 'Billing › '.$heading,
        'heading' => $heading,
        'content' => $heading."\n".$body,
        'helpVersion' => 'v1',
        'similarity' => 0.9,
        'keywordRank' => null,
        'score' => 0.03,
        'chunkKey' => sha1($source.$heading),
    ], $overrides));
}

function chunkRow(array $overrides = []): HelpChunk
{
    $content = $overrides['content'] ?? 'A late fee is applied automatically 30 days after the due date.';

    static $counter = 0;

    $counter++;

    return HelpChunk::create(array_merge([
        'source' => 'help/billing/late-fees.md',
        'position' => $counter,
        'heading' => 'Late fees › When a late fee is applied',
        'content' => $content,
        'tokens' => 40,
        'title' => 'Late fees',
        'heading_path' => 'Billing › Late fees › When a late fee is applied',
        'tags' => ['area' => 'billing'],
        'help_version' => 'v1',
        'chunk_key' => sha1('chunk'.$counter),
        'embedding' => fakeEmbeddingVector($content),
    ], $overrides));
}

// The exit code and everything a command printed, for output that is a table or whose lines come in an order the test should not depend on.
function artisanOutput(string $command, array $parameters = []): array
{
    $code = Artisan::call($command, $parameters);

    return [$code, Artisan::output()];
}
