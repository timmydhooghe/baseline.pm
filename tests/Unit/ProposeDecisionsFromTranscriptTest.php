<?php

use App\Actions\Governance\ProposeDecisionsFromTranscript;
use App\Enums\DecisionSource;

/**
 * @return list<array<string, mixed>>
 */
function propose(string $transcript): array
{
    return (new ProposeDecisionsFromTranscript)($transcript);
}

test('a marked line proposes what follows the marker', function () {
    $proposals = propose("Anna: Long discussion.\nDecision: SSO is excluded from phase 1.");

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['decision'])->toBe('SSO is excluded from phase 1.')
        ->and($proposals[0]['title'])->toBe('SSO is excluded from phase 1.')
        ->and($proposals[0]['source'])->toBe(DecisionSource::Transcript);
});

test('the phrases people actually use to close something are recognised', function (string $line) {
    expect(propose("Anna: {$line}"))->toHaveCount(1);
})->with([
    'We decided to drop the legacy importer.',
    "We've decided to drop the legacy importer.",
    'We have agreed that reporting ships with milestone 2.',
    'It was agreed that the migration window is the 14th.',
    'The decision is to keep authentication local.',
    "We're going with the vendor SDK.",
    "Let's go with weekly demos on Thursday.",
    'We settled on a monthly cadence.',
]);

test('deliberation is not a decision', function (string $line) {
    expect(propose("Anna: {$line}"))->toBeEmpty();
})->with([
    'I wonder whether caching would help.',
    'Maybe we should look at single sign-on next month.',
    'Can somebody check the export job?',
    'That would be about three days of work.',
]);

test('only the sentence that closes something is proposed', function () {
    $proposals = propose('Anna: The export failed twice. We agreed to rebuild the importer. Tom will scope it.');

    expect($proposals[0]['decision'])->toBe('We agreed to rebuild the importer.');
});

test('the same decision said twice is proposed once', function () {
    $proposals = propose(
        "Anna: We agreed to rebuild the importer.\n".
        "Tom: Right.\n".
        'Anna: We agreed to rebuild the importer.',
    );

    expect($proposals)->toHaveCount(1);
});

test('every proposal carries the lines that led up to it', function () {
    $proposals = propose(
        "Anna: The export failed validation twice.\n".
        "Tom: Rebuilding it is three days.\n".
        'Decision: we rebuild the importer.',
    );

    expect($proposals[0]['context'])
        ->toContain('The export failed validation twice.')
        ->toContain('Rebuilding it is three days.')
        ->and($proposals[0]['transcript_excerpt'])->toBe($proposals[0]['context']);
});

test('speakers become participants without an invented affiliation', function () {
    $proposals = propose(
        "[10:02] Anna Peeters: Morning.\n".
        "Tom Verhaeghe: Morning.\n".
        'Anna Peeters: We agreed to ship on Friday.',
    );

    expect($proposals[0]['participants'])->toBe([
        ['name' => 'Anna Peeters', 'affiliation' => null],
        ['name' => 'Tom Verhaeghe', 'affiliation' => null],
    ]);
});

test('a long transcript proposes at most one screenful', function () {
    $lines = [];

    for ($index = 0; $index < 30; $index++) {
        $lines[] = "Anna: We agreed on point number {$index}.";
    }

    expect(propose(implode("\n", $lines)))
        ->toHaveCount(ProposeDecisionsFromTranscript::MAX_PROPOSALS);
});

test('an empty transcript proposes nothing', function () {
    expect(propose("   \n\n  "))->toBeEmpty();
});
