## Ledgerly: rules that apply everywhere
- Never call an AI provider directly. Use `App\Ai\Contracts\ChatModel` (through the `Ai` facade) so tracing, redaction, and budgets apply (Volume 2, Chapters 31, 34, 36).
- Every tool a model can call goes through `ToolGate::dispatch`. New tools need a side-effect test (Chapter 33).
- Prompts are classes in `app/Ai/Prompts`. A prompt change needs an updated snapshot and `php artisan eval:check` (Chapter 29).
- Every new model call has a feature key for the cost ledger (Chapter 36).
- Fixtures and seeders contain no real customer data. Use factories and the seeders in `database/seeders`.
- Prices in `config/ai-pricing.php` change only with a source URL and date.
- Before proposing a change: `php artisan test --parallel`, `vendor/bin/pint --test`, and `php artisan ai:contract-test` if a driver changed.
