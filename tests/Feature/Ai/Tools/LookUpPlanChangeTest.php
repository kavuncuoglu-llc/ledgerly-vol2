<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolGate;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Models\Customer;
use App\Models\PlanChange;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Chapter 49, Topic 8: the acceptance tests an agent is given before it writes the tool.
function planChangeTool(): object
{
    return app(ToolRegistry::class)->native()->get('look_up_plan_change');
}

function dispatchPlanChange(User $user, array $args = []): array
{
    return app(ToolGate::class)->dispatch(planChangeTool(), $args, new ToolContext($user))->toArray();
}

function rowCounts(): array
{
    return collect(['plan_changes', 'customers', 'invoices', 'invoice_credits', 'payment_retries', 'tickets', 'users'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->all();
}

describe('look_up_plan_change is registered with the tool gate', function () {
    it('is a native read tool', function () {
        expect(planChangeTool())->not->toBeNull()
            ->and(planChangeTool()->kind())->toBe(ToolKind::Read)
            ->and(app(ToolRegistry::class)->get('look_up_plan_change'))->not->toBeNull();
    });

    it('takes no argument that chooses an account', function () {
        $user = User::factory()->owner(Customer::factory()->create())->create();

        $result = dispatchPlanChange($user, ['account' => 'someone-else']);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toBe('invalid arguments');
    });

    it('rejects a field it does not declare', function () {
        $user = User::factory()->owner(Customer::factory()->create())->create();

        expect(dispatchPlanChange($user, ['customer_id' => 99])['ok'])->toBeFalse();
    });
});

describe('look_up_plan_change returns the right record', function () {
    beforeEach(function () {
        $this->seed();

        $this->maya = User::find(12);
    });

    it('says when and how Acme Studios last changed plan', function () {
        $result = dispatchPlanChange($this->maya);

        expect($result)->toBe([
            'ok' => true,
            'found' => true,
            'last_change' => [
                'from_plan' => 'starter',
                'to_plan' => 'pro',
                'type' => 'upgrade',
                'changed_at' => '2026-01-14',
                'how' => 'by the customer, in the app',
            ],
            'changes_on_record' => 2,
        ]);
    });

    it('never shows the staff note', function () {
        expect(json_encode(dispatchPlanChange($this->maya)))->not->toContain('seat limit');
    });

    it('returns the change of the signed-in customer and nothing of anyone else', function () {
        $other = User::find(13);

        $mine = dispatchPlanChange($this->maya)['last_change'];

        $theirs = dispatchPlanChange($other);

        $ownRows = PlanChange::where('customer_id', $other->customer_id)->count();

        expect($theirs['changes_on_record'])->toBe($ownRows)
            ->and($theirs['last_change'])->not->toBe($mine);
    });

    it('says nothing is on record for an account without a plan history', function () {
        $user = User::factory()->owner(Customer::factory()->create())->create();

        expect(dispatchPlanChange($user))->toBe(['ok' => true, 'found' => false]);
    });

    it('takes the most recent change when an account moved several times', function () {
        $customer = Customer::factory()->create();

        $user = User::factory()->owner($customer)->create();

        foreach ([['starter', 'pro', '2025-06-01'], ['pro', 'starter', '2025-12-01'], ['starter', 'pro', '2026-02-02']] as [$from, $to, $on]) {
            PlanChange::create(['customer_id' => $customer->id, 'from_plan' => $from, 'to_plan' => $to, 'change_type' => $to === 'pro' ? 'upgrade' : 'downgrade', 'via' => 'renewal', 'changed_at' => $on.' 10:00:00']);
        }

        $result = dispatchPlanChange($user);

        expect($result['last_change']['changed_at'])->toBe('2026-02-02')
            ->and($result['last_change']['how'])->toBe('at the renewal date')
            ->and($result['changes_on_record'])->toBe(3);
    });
});

describe('look_up_plan_change has no side effects (Chapter 33)', function () {
    it('changes no table and writes only its audit row', function () {
        $this->seed();

        $before = rowCounts();

        $auditBefore = ToolCall::count();

        dispatchPlanChange(User::find(12));

        expect(rowCounts())->toBe($before)
            ->and(ToolCall::count())->toBe($auditBefore + 1)
            ->and(ToolCall::latest('id')->first()->decision)->toBe('allowed');
    });

    it('runs under the read-only database role, whose grants are the policy', function () {
        $can = fn (string $privilege, string $column) => DB::selectOne('select has_column_privilege(?, ?, ?, ?) as ok', [ToolGate::READ_ROLE, 'plan_changes', $column, $privilege])->ok;

        expect($can('SELECT', 'to_plan'))->toBeTrue()
            ->and($can('SELECT', 'changed_at'))->toBeTrue()
            ->and($can('SELECT', 'note'))->toBeFalse()
            ->and($can('UPDATE', 'to_plan'))->toBeFalse()
            ->and(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', [ToolGate::READ_ROLE, 'plan_changes', 'INSERT, UPDATE, DELETE'])->ok)->toBeFalse()
            ->and(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', [ToolGate::WRITE_ROLE, 'plan_changes', 'SELECT'])->ok)->toBeFalse();
    });
});
