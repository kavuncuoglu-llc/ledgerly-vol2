<?php

namespace App\Ai\Tools\CalculateProration;

use App\Ai\Schema\Describe;

final readonly class CalculateProrationArgs
{
    public function __construct(
        #[Describe('What to calculate: plan_change (the charge for moving to a dearer plan part-way through a period) or late_fee (the fee on an overdue balance).')] public string $kind,
        #[Describe('plan_change: the current plan price for a full period. Null for a late_fee.')] public ?float $old_price,
        #[Describe('plan_change: the new plan price for a full period. Null for a late_fee.')] public ?float $new_price,
        #[Describe('plan_change: whole days left in the period when the change happens. Null for a late_fee.')] public ?int $days_remaining,
        #[Describe('plan_change: whole days in the period, e.g. 30. Null for a late_fee.')] public ?int $days_in_period,
        #[Describe('late_fee: the outstanding balance of the invoice, without earlier late fees. Null for a plan_change.')] public ?float $balance,
        #[Describe('late_fee: whole days past the due date, not counting days in a dispute or a payment plan. Null for a plan_change.')] public ?int $days_overdue,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            $d['kind'],
            isset($d['old_price']) ? (float) $d['old_price'] : null,
            isset($d['new_price']) ? (float) $d['new_price'] : null,
            isset($d['days_remaining']) ? (int) $d['days_remaining'] : null,
            isset($d['days_in_period']) ? (int) $d['days_in_period'] : null,
            isset($d['balance']) ? (float) $d['balance'] : null,
            isset($d['days_overdue']) ? (int) $d['days_overdue'] : null,
        );
    }

    public static function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:plan_change,late_fee'],
            'old_price' => ['nullable', 'numeric', 'min:0', 'max:100000', 'required_if:kind,plan_change'],
            'new_price' => ['nullable', 'numeric', 'min:0', 'max:100000', 'required_if:kind,plan_change'],
            'days_remaining' => ['nullable', 'integer', 'min:0', 'max:366', 'required_if:kind,plan_change'],
            'days_in_period' => ['nullable', 'integer', 'min:1', 'max:366', 'required_if:kind,plan_change', 'gte:days_remaining'],
            'balance' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'required_if:kind,late_fee'],
            'days_overdue' => ['nullable', 'integer', 'min:0', 'max:3650', 'required_if:kind,late_fee'],
        ];
    }
}
