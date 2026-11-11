<?php

namespace App\Ai\Triage;

use App\Ai\Schemas\Category;

final class Assignment
{
    public static function for(Category $category, Urgency $urgency): string
    {
        return match ($category) {
            Category::Billing => 'billing-team',
            Category::HowTo => 'docs-team',
            Category::Bug => $urgency === Urgency::Urgent
                ? 'engineering-oncall'
                : 'engineering',
            Category::Account => 'account-team',
            Category::Other => 'support-queue',
        };
    }
}
