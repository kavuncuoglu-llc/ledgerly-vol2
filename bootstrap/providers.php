<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\EvalServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TracingServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    HorizonServiceProvider::class,
    EvalServiceProvider::class,
    TracingServiceProvider::class,
];
