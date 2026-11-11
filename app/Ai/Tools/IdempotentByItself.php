<?php

namespace App\Ai\Tools;

// A low-risk write that already makes a repeated call a no-op in the database, so the gate does not wrap it again.
interface IdempotentByItself {}
