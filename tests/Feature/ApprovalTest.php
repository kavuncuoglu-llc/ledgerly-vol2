<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Models\PendingToolCall;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\RefundTool;

beforeEach(function () {
    RefundTool::$runs = 0;

    bindTools(new RefundTool);

    [$this->user] = customerWithInvoice();

    fakeChatSequence(toolCallsBody([['call_9', 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6]]]));

    $this->pending = app(ToolRunner::class)->run(
        [['role' => 'user', 'content' => 'Refund the late fee']],
        new ToolContext($this->user),
        ['profile' => 'assistant'],
        'support.reply',
    )->pendingApprovals[0];

    Http::swap(new Factory);
});

it('shows the owner what the assistant wants to do', function () {
    $this->actingAs($this->user)
        ->get(route('support.approvals.show', $this->pending))
        ->assertOk()
        ->assertSeeText('Refund $21.60 late fee on invoice #4471')
        ->assertSee(route('support.approve', $this->pending))
        ->assertSee(route('support.decline', $this->pending));
});

it('lets a token holder poll the status as JSON', function () {
    $token = $this->user->createToken('cursor-jeremy')->plainTextToken;

    $this->getJson(route('support.approvals.show', $this->pending), ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJson(['id' => $this->pending->id, 'tool' => 'issue_refund', 'summary' => 'Refund $21.60 late fee on invoice #4471', 'status' => 'pending', 'token' => approvalToken($this->pending)]);
});

it('runs the tool and returns the resumed answer on approve', function () {
    fakeChatSequence(chatBody('I refunded $21.60.'));

    $this->actingAs($this->user)
        ->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])
        ->assertOk()
        ->assertExactJson(['status' => 'approved', 'answer' => 'I refunded $21.60.', 'truncated' => false]);

    expect(RefundTool::$runs)->toBe(1)
        ->and(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['pending_approval', 'approved']);
});

it('redirects back to the page with the answer when a form posts', function () {
    fakeChatSequence(chatBody('I refunded $21.60.'));

    $this->actingAs($this->user)
        ->post(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])
        ->assertRedirect(route('support.approvals.show', $this->pending))
        ->assertSessionHas('answer', 'I refunded $21.60.');
});

it('does not run the tool on decline and says so to the model', function () {
    fakeChatSequence(chatBody('Understood, I will not refund it.'));

    $this->actingAs($this->user)
        ->postJson(route('support.decline', $this->pending))
        ->assertOk()
        ->assertJson(['status' => 'declined', 'answer' => 'Understood, I will not refund it.']);

    expect(RefundTool::$runs)->toBe(0);
});

it('answers a request only once', function () {
    fakeChatSequence(chatBody('I refunded $21.60.'));

    $this->actingAs($this->user)->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertOk();

    $this->actingAs($this->user)->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertStatus(410);

    expect(RefundTool::$runs)->toBe(1);
});

it('keeps another user out of the request', function () {
    $stranger = User::factory()->owner()->create();

    $this->actingAs($stranger)->get(route('support.approvals.show', $this->pending))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertForbidden();
    $this->actingAs($stranger)->postJson(route('support.decline', $this->pending))->assertForbidden();

    expect(RefundTool::$runs)->toBe(0)
        ->and(PendingToolCall::find($this->pending->id)->status)->toBe('pending');
});

it('turns away a guest', function () {
    $this->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertUnauthorized();
    $this->getJson(route('support.approvals.show', $this->pending))->assertUnauthorized();
});

it('returns 404 for a request that does not exist', function () {
    $this->actingAs($this->user)->postJson(route('support.approve', 999999))->assertNotFound();
});

it('shows the approval link in the ask response when the run pauses', function () {
    fakeChatSequence(toolCallsBody([['call_10', 'issue_refund', ['invoice_number' => '4471', 'amount' => 5]]]));

    askAssistant($this->user, ['question' => 'Refund 5 dollars on 4471'])
        ->assertOk()
        ->assertJsonPath('answer', 'I need your approval before I can do this: Refund $5.00 late fee on invoice #4471')
        ->assertJsonPath('pending_approvals.0.summary', 'Refund $5.00 late fee on invoice #4471')
        ->assertJsonPath('pending_approvals.0.url', fn (string $url) => str_contains($url, '/support/approvals/'));
});
