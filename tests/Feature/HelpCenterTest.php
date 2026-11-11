<?php

use App\Help\HelpArticle;

beforeEach(function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource(
            'Understanding late fees',
            'billing',
            "A late fee is an extra charge.\n\n## How the fee is calculated\n\nThe fee is 1.5% per month.\n\n## When a late fee is applied\n\nThirty days after the due date.\n",
        ),
        'billing/reminders.md' => helpArticleSource('Payment reminders', 'billing'),
        'account/profile.md' => helpArticleSource('Your profile', 'account'),
    ]);
});

it('lists every category with its articles', function () {
    $this->get('/help')
        ->assertOk()
        ->assertSeeText('Help center')
        ->assertSeeText('Account')
        ->assertSeeText('Billing')
        ->assertSeeText('Understanding late fees')
        ->assertSeeText('Your profile');
});

it('shows a friendly empty state when there are no articles', function () {
    helpLibrary([]);

    $this->get('/help')
        ->assertOk()
        ->assertSeeText('There are no help articles yet');
});

it('survives a help directory that does not exist', function () {
    config(['ledgerly.help_path' => sys_get_temp_dir().'/ledgerly-missing-'.uniqid()]);

    $this->get('/help')->assertOk();
    $this->get('/help/billing')->assertNotFound();
    $this->get('/')->assertOk();
});

it('lists the articles in one category', function () {
    $this->get('/help/billing')
        ->assertOk()
        ->assertSeeText('Understanding late fees')
        ->assertSeeText('Payment reminders')
        ->assertDontSeeText('Your profile');
});

it('renders an article from markdown with linkable headings', function () {
    $this->get('/help/billing/late-fees')
        ->assertOk()
        ->assertSeeText('Understanding late fees')
        ->assertSeeText('A short summary.')
        ->assertSee('id="how-the-fee-is-calculated"', false)
        ->assertSee('id="when-a-late-fee-is-applied"', false)
        ->assertSeeText('The fee is 1.5% per month.');
});

it('does not repeat the title as a second h1', function () {
    $html = $this->get('/help/billing/late-fees')->getContent();

    expect(substr_count($html, '<h1>'))->toBe(1);
});

it('returns 404 for unknown categories, articles and odd paths', function () {
    $this->get('/help/nothing')->assertNotFound();
    $this->get('/help/billing/nothing')->assertNotFound();
    $this->get('/help/billing/..%2Faccount%2Fprofile')->assertNotFound();
});

it('builds article urls that match the citation format used later in the book', function () {
    expect(HelpArticle::find('billing', 'late-fees')->url())->toBe('/help/billing/late-fees');
});
