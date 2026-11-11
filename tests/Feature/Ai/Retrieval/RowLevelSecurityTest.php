<?php

use App\Http\Middleware\SetTenantForDatabase;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function sourceFor(Tenant $tenant, string $label): MessageSource
{
    $conversation = conversationWithMessages($tenant, ['question']);

    $message = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('role', 'assistant')->firstOrFail();

    return MessageSource::withoutGlobalScopes()->create([
        'message_id' => $message->id,
        'tenant_id' => $tenant->id,
        'label' => $label,
        'source' => 'help/billing/late-fees.md',
        'heading_path' => 'Billing › Late fees',
        'url' => '/help/billing/late-fees',
    ]);
}

function asNonOwnerRole(Closure $callback): mixed
{
    DB::statement('CREATE ROLE ledgerly_rls_probe NOLOGIN');
    DB::statement('GRANT SELECT ON message_sources, help_chunks TO ledgerly_rls_probe');
    DB::statement('SET LOCAL ROLE ledgerly_rls_probe');

    try {
        return $callback();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function tenantSetting(string $value): void
{
    DB::statement('SELECT set_config(?, ?, false)', ['app.tenant_id', $value]);
}

afterEach(function () {
    tenantSetting('');
});

it('makes the database itself refuse another tenant\'s message_sources, even for raw SQL', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    sourceFor($a, 'a-label');
    sourceFor($b, 'b-label');

    $seen = asNonOwnerRole(function () use ($a, $b) {
        tenantSetting((string) $a->id);
        $asA = DB::table('message_sources')->pluck('label')->all();

        tenantSetting((string) $b->id);
        $asB = DB::table('message_sources')->pluck('label')->all();

        tenantSetting('0');
        $asNobody = DB::table('message_sources')->pluck('label')->all();

        return [$asA, $asB, $asNobody];
    });

    expect($seen)->toBe([['a-label'], ['b-label'], []]);
});

it('hides a tenant\'s private help passage from other tenants at the database, and shows the shared ones to everybody', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    chunkRow(['source' => 'help/shared.md', 'chunk_key' => 'shared']);
    chunkRow(['source' => 'help/private-a.md', 'tenant_id' => $a->id, 'chunk_key' => 'private-a']);

    $seen = asNonOwnerRole(function () use ($a, $b) {
        tenantSetting((string) $a->id);
        $asA = DB::table('help_chunks')->orderBy('source')->pluck('source')->all();

        tenantSetting((string) $b->id);
        $asB = DB::table('help_chunks')->orderBy('source')->pluck('source')->all();

        return [$asA, $asB];
    });

    expect($seen)->toBe([['help/private-a.md', 'help/shared.md'], ['help/shared.md']]);
});

it('sets the signed-in user\'s tenant for the database on every web request and clears it afterwards', function () {
    Route::middleware('web')->get('/_probe', fn () => response()->json(['tenant' => DB::selectOne("select current_setting('app.tenant_id', true) as value")->value]));

    $user = User::factory()->create();

    $this->actingAs($user)->get('/_probe')->assertOk()->assertJson(['tenant' => (string) $user->tenant_id]);

    expect(DB::selectOne("select nullif(current_setting('app.tenant_id', true), '') as value")->value)->toBeNull();
});

it('gives a guest the tenant 0, which matches nothing', function () {
    Route::middleware('web')->get('/_probe', fn () => response()->json(['tenant' => DB::selectOne("select current_setting('app.tenant_id', true) as value")->value]));

    $this->get('/_probe')->assertOk()->assertJson(['tenant' => '0']);
});

it('is registered in the web group', function () {
    expect(app('router')->getMiddlewareGroups()['web'])->toContain(SetTenantForDatabase::class);
});
