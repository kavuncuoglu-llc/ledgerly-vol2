<?php

use App\Ai\Privacy\AllowList;
use App\Ai\Privacy\Detectors\CardNumber;
use App\Ai\Privacy\Detectors\EmailAddress;
use App\Ai\Privacy\Detectors\Iban;
use App\Ai\Privacy\Detectors\PhoneNumber;
use App\Ai\Privacy\Detectors\UsSocialSecurityNumber;
use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Privacy\Span;
use Tests\Support\FakeNer;

pest()->group('privacy');

function detectorWith(?FakeNer $ner = null): PersonalDataDetector
{
    return new PersonalDataDetector(
        detectors: [new CardNumber, new UsSocialSecurityNumber, new Iban, new EmailAddress(['ledgerly.test']), new PhoneNumber('US')],
        allowList: new AllowList(terms: ['Ledgerly']),
        ner: $ner ?? new FakeNer,
    );
}

function types(array $spans): array
{
    return array_map(fn (Span $span) => $span->type, $spans);
}

describe('card numbers', function () {
    it('finds a Luhn-valid number, with spaces or dashes', function (string $card) {
        $spans = (new CardNumber)->find("my card is {$card} thanks");

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->text)->toBe($card)
            ->and($spans[0]->offset)->toBe(11);
    })->with(['4242 4242 4242 4242', '4242-4242-4242-4242', '4242424242424242', '3782 822463 10005']);

    it('leaves a 16-digit string that fails the checksum alone', function () {
        expect((new CardNumber)->find('order 4242 4242 4242 4243 shipped'))->toBe([]);
    });

    it('ignores short numbers and long digit runs that are not a card', function () {
        expect((new CardNumber)->find('invoice 4471, total 1200.00, ref 1234567'))->toBe([]);
    });
});

describe('social security numbers', function () {
    it('finds one that could have been issued', function () {
        $spans = (new UsSocialSecurityNumber)->find('SSN 123-45-6789 on file');

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->type)->toBe('SSN')
            ->and($spans[0]->text)->toBe('123-45-6789');
    });

    it('skips what is never issued', function (string $candidate) {
        expect((new UsSocialSecurityNumber)->find("number {$candidate}"))->toBe([]);
    })->with(['000-12-3456', '666-12-3456', '900-12-3456', '987-65-4321', '123-00-6789', '123-45-0000']);

    it('does not take a piece of a longer dashed number', function () {
        expect((new UsSocialSecurityNumber)->find('ref 1-123-45-6789-22'))->toBe([]);
    });
});

describe('email addresses', function () {
    it('finds an address and not the one the app sends from', function () {
        $spans = (new EmailAddress(['ledgerly.test']))->find('From support@ledgerly.test to dana@acme.example and sam@acme-studios.example.com');

        expect(array_map(fn (Span $s) => $s->text, $spans))->toBe(['dana@acme.example', 'sam@acme-studios.example.com']);
    });

    it('wants a domain with a dot and valid labels', function (string $text) {
        expect((new EmailAddress)->find($text))->toBe([]);
    })->with(['write to dana@localhost please', 'a@-bad-.com', 'user@@example.com']);
});

describe('phone numbers', function () {
    it('finds numbers valid for their region, with or without a country code', function (string $number) {
        $spans = (new PhoneNumber('US'))->find("call me on {$number} after 3pm");

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->text)->toBe($number);
    })->with(['+1 415 555 0142', '+1 (415) 555-0142', '415-555-0142', '+44 20 7946 0958']);

    it('does not take invoice numbers, dates, totals or ticket ids for phone numbers', function () {
        expect((new PhoneNumber('US'))->find('Why is INV-4471 late? Issued 2026-02-01, due 2026-03-03, total $1,200.00, ticket T-2291, ref 4151234567'))->toBe([]);
    });

    it('reports byte offsets even after multibyte characters', function () {
        $text = 'Grüße — rufen Sie +44 20 7946 0958 an';

        $span = (new PhoneNumber('US'))->find($text)[0];

        expect(substr($text, $span->offset, $span->length))->toBe('+44 20 7946 0958');
    });
});

describe('IBANs', function () {
    it('finds one whose length and check digits are right', function (string $iban) {
        $spans = (new Iban)->find("Please refund to {$iban} today");

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->text)->toBe($iban);
    })->with(['DE89 3704 0044 0532 0130 00', 'GB82WEST12345698765432', 'GB82 WEST 1234 5698 7654 32', 'NL91 ABNA 0417 1643 00']);

    it('rejects a wrong check digit and a wrong length', function (string $candidate) {
        expect((new Iban)->find("account {$candidate}"))->toBe([]);
    })->with(['DE89 3704 0044 0532 0130 01', 'DE89 3704 0044 0532 0130', 'XX00 1234 5678 9012 3456']);

    it('stops at the end of the number when a capitalised word follows', function () {
        $spans = (new Iban)->find('DE89 3704 0044 0532 0130 00 AND then');

        expect($spans[0]->text)->toBe('DE89 3704 0044 0532 0130 00');
    });
});

describe('the detector', function () {
    it('never redacts the app\'s own identifiers', function () {
        $text = 'Please send the receipt for #4471 and INV-4471, ticket T-2291, see [Late fees › How the fee is calculated].';

        expect(detectorWith()->findPatterns($text))->toBe([])
            ->and(detectorWith()->mask($text))->toBe($text);
    });

    it('lets the first detector keep text two of them claim', function () {
        $spans = detectorWith()->findPatterns('card 4242 4242 4242 4242 ok');

        expect(types($spans))->toBe(['CARD']);
    });

    it('masks irreversibly, naming the type', function () {
        $masked = detectorWith()->mask('Mail dana@acme.example, call +1 415 555 0142, card 4242 4242 4242 4242, SSN 123-45-6789.');

        expect($masked)->toBe('Mail [email], call [phone], card [card], SSN [ssn].');
    });

    it('adds names and addresses from the name detector, in text order', function () {
        $ner = FakeNer::knowing(['Dana Whitfield' => 'PERSON', '12 Harbor St' => 'ADDRESS']);

        $spans = detectorWith($ner)->find('Dana Whitfield, 12 Harbor St, dana@acme.example');

        expect(types($spans))->toBe(['PERSON', 'ADDRESS', 'EMAIL']);
    });

    it('does not take a product name for a person, nor a name inside a help-center label', function () {
        $ner = FakeNer::knowing(['Ledgerly' => 'PERSON', 'Dana' => 'PERSON']);

        $spans = detectorWith($ner)->find('Ledgerly says Dana wrote, see [Dana Whitfield on late fees]');

        expect(array_map(fn (Span $s) => $s->text, $spans))->toBe(['Dana']);
    });

    it('says whether names can be screened', function () {
        $ner = new FakeNer;

        $detector = detectorWith($ner);

        expect($detector->nerAvailable())->toBeTrue();

        $ner->up = false;

        expect($detector->nerAvailable())->toBeFalse();
    });

    it('masks names too when the service is up, and falls back to patterns when it is not', function () {
        $ner = FakeNer::knowing(['Dana Whitfield' => 'PERSON']);

        $detector = detectorWith($ner);

        expect($detector->maskWithNames('Dana Whitfield, dana@acme.example'))->toBe('[person], [email]');

        $ner->up = false;

        expect($detector->maskWithNames('Dana Whitfield, dana@acme.example'))->toBe('Dana Whitfield, [email]');
    });
});
