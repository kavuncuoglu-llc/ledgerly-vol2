<?php

namespace App\Ai\Contracts;

enum Capability: string
{
    case Tools = 'tools';
    case StructuredOutput = 'structured';
    case Vision = 'vision';
    case CacheBreakpoints = 'cache_breakpoints';
    case Seed = 'seed';
    case Reasoning = 'reasoning';
    case ContextWindowOverride = 'ctx_override';
    case NativeStructuredOutput = 'native_structured';
    case Batch = 'batch';
    case Grammar = 'grammar';
    case Tokenize = 'tokenize';
    case PrefixCache = 'prefix_cache';
    case Lora = 'lora';
}
