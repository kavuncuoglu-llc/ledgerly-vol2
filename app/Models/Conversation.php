<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Conversation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary_through_seq' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('seq');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    // Chapter 40, Topic 3: the export's first query. The tenant opted in (the setting is off until its admin turns it on), the user did not opt out, and a tenant with a residency only gives conversations to an export that runs in its region. A conversation that is not eligible is never copied anywhere.
    public function scopeEligibleForTraining(Builder $query, ?string $region = null): Builder
    {
        return $query
            ->whereHas('tenant', function (Builder $tenant) use ($region) {
                $tenant->where('training_use', true);

                $tenant->where(function (Builder $residency) use ($region) {
                    $residency->whereNull('data_residency');

                    if ($region !== null) {
                        $residency->orWhere('data_residency', $region);
                    }
                });
            })
            ->whereHas('user', fn (Builder $user) => $user->where('training_excluded', false));
    }

    // A thumbs-up on a reply, or a reply the customer did not come back about (Chapter 30's signals): the conversations the export may learn from.
    public function scopeWithPositiveSignal(Builder $query): Builder
    {
        return $query->where(function (Builder $positive) {
            $positive->whereHas('messages', fn (Builder $message) => $message->where('feedback', 'up'));

            $positive->orWhereIn('id', AnswerSignal::query()->where('resolved', true)->where('escalated', false)->select('conversation_id'));
        });
    }

    // The replies the assistant gave and the customer saw, each with the question it answers.
    /** @return Collection<int, Message> */
    public function answeredTurns(): Collection
    {
        return $this->messages()
            ->where('role', 'assistant')
            ->where('status', 'completed')
            ->whereNotNull('question')
            ->whereNotNull('content')
            ->get();
    }
}
