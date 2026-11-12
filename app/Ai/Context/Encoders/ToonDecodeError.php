<?php

namespace App\Ai\Context\Encoders;

use RuntimeException;

// Chapter 48, Topic 4: model-written TOON that does not decode strictly (a header that promises three rows and delivers two, a field count that does not match). Treated like any other structured-output failure: retry or repair.
final class ToonDecodeError extends RuntimeException {}
