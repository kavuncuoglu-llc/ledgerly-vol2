<?php

namespace App\Ai\Privacy;

// A tool that implements this says: the value I act on may arrive as "<EMAIL_1>", and the gate may look it up in this conversation's vault. No other tool gets a restored value, so a fooled model cannot use restoration to send a customer's data somewhere new.
interface AcceptsVaultReferences {}
