<?php

namespace App\Providers;

use App\Ai\AiManager;
use App\Ai\Cache\CachePolicy;
use App\Ai\Cache\HelpVersion;
use App\Ai\Cache\PrefixCacheEstimator;
use App\Ai\Cache\SemanticCache;
use App\Ai\Context\ContextAssembler;
use App\Ai\Context\ProviderProfile;
use App\Ai\Contracts\Reranker;
use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\EloquentConversationStore;
use App\Ai\Embeddings\Embedder;
use App\Ai\Encoding\DataEncoder;
use App\Ai\Encoding\Formats\JsonFormat;
use App\Ai\Encoding\Formats\MarkdownFormat;
use App\Ai\Encoding\Formats\ToonFormat;
use App\Ai\Encoding\Formats\TsvFormat;
use App\Ai\Encoding\Formats\YamlFormat;
use App\Ai\Events\ModelCalled;
use App\Ai\Health\CircuitBreaker;
use App\Ai\Ingestion\BoilerplateStripper;
use App\Ai\Listeners\RecordModelCall;
use App\Ai\Parsing\DocumentParser;
use App\Ai\Parsing\DocxParser;
use App\Ai\Parsing\HtmlParser;
use App\Ai\Parsing\MarkdownParser;
use App\Ai\Parsing\ParserRouter;
use App\Ai\Parsing\PdftotextParser;
use App\Ai\Parsing\SidecarPdfParser;
use App\Ai\Python\RedisStreams;
use App\Ai\Python\Streams;
use App\Ai\Retrieval\PgRetriever;
use App\Ai\Retrieval\Retriever;
use App\Ai\Retrieval\RetrieverFactory;
use App\Ai\Retrieval\SidecarReranker;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Tokens\LlamaServerTokenCounter;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;
use App\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Streams::class, fn () => RedisStreams::fromConfig());

        $this->app->singleton(TokenCounter::class, fn () => match (config('ai.token_counter')) {
            'tiktoken' => new TiktokenCounter,
            'server' => $this->serverCounter(),
            default => new RatioCounter,
        });

        $this->app->singleton(AiManager::class);

        $this->app->singleton(CircuitBreaker::class, fn () => new CircuitBreaker(Cache::store(config('ai.breaker.store') ?: null)));

        $this->app->singleton(Embedder::class, fn ($app) => new Embedder($app->make(AiManager::class), config('ai.embed_model')));

        $this->app->singleton(CurrentTenant::class);

        $this->app->singleton(HelpVersion::class);
        $this->app->bind(BoilerplateStripper::class, fn () => BoilerplateStripper::fromConfig());
        $this->app->singleton(PgRetriever::class, fn ($app) => PgRetriever::fromConfig($app->make(Embedder::class), $app->make(HelpVersion::class)));
        $this->app->singleton(SidecarClient::class, fn () => SidecarClient::fromConfig());
        $this->app->bind(DocumentParser::class, fn ($app) => new ParserRouter([
            new MarkdownParser,
            new HtmlParser,
            new DocxParser,
            new SidecarPdfParser($app->make(SidecarClient::class), new PdftotextParser),
        ]));
        $this->app->bind(Reranker::class, fn ($app) => new SidecarReranker($app->make(SidecarClient::class)));
        $this->app->bind(Retriever::class, fn ($app) => $app->make(RetrieverFactory::class)->make());
        $this->app->singleton(CachePolicy::class);
        $this->app->singleton(SemanticCache::class);
        $this->app->singleton(PrefixCacheEstimator::class);

        $this->app->bind(ProviderProfile::class, fn () => ProviderProfile::fromConfig());
        $this->app->bind(ContextAssembler::class);                       // one per prompt: it holds the slots it is filling

        $this->app->singleton(ConversationStore::class, EloquentConversationStore::class);

        $this->app->singleton(DataEncoder::class, fn () => new DataEncoder([
            'toon' => new ToonFormat,
            'tsv' => new TsvFormat,
            'markdown' => new MarkdownFormat,
            'json' => new JsonFormat,
            'json-pretty' => new JsonFormat(pretty: true),
            'yaml' => new YamlFormat,
        ]));
    }

    // llama-server's /tokenize, from the `local` role when that is llama-server, else from `local-llama`; an Ollama-only setup has nothing to ask and keeps the estimate.
    private function serverCounter(): TokenCounter
    {
        $ai = $this->app->make(AiManager::class);

        foreach (['local', 'local-llama'] as $name) {
            if (! $ai->configured($name)) {
                continue;
            }

            $provider = $ai->config($name);

            if (($provider['native'] ?? null) === 'llama-server') {
                return new LlamaServerTokenCounter((string) $provider['base_url'], $provider['api_key'] ?? null);
            }
        }

        return new RatioCounter;
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Event::listen(ModelCalled::class, RecordModelCall::class);

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        Blade::directive('money', fn (string $expression) => "<?php echo e(\\App\\Support\\Money::format({$expression})); ?>");
    }
}
