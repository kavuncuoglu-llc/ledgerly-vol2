<?php

namespace App\Ai\Contracts\Stream;

// The small closed set a stream can contain. Drivers translate their provider's chunks into these; features never see a provider's JSON.
interface StreamEvent {}
