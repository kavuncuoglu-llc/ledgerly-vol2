<?php

namespace App\Facades;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\Reranker;
use App\Ai\Testing\FakeAi;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ChatModel chat(?string $name = null)
 * @method static ChatModel for(string $feature)
 * @method static EmbeddingModel embed(?string $name = null)
 * @method static Reranker reranker(?string $name = null)
 * @method static string providerFor(string $feature)
 * @method static array featureSettings(string $feature)
 * @method static string|null escalationFor(string $feature)
 * @method static bool configured(string $name)
 * @method static string model(string $name)
 * @method static FakeAi fake()
 * @method static void assertSent(Closure $matches)
 * @method static void assertSentCount(string $feature, int $times)
 * @method static void assertNothingSentTo(string $provider)
 * @method static void assertNothingSent()
 *
 * @see AiManager
 */
class Ai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AiManager::class;
    }
}
