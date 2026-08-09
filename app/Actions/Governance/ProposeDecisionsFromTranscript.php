<?php

namespace App\Actions\Governance;

use App\Enums\DecisionSource;
use Illuminate\Support\Str;

/**
 * Turns a pasted meeting transcript into proposed decision drafts (FA-18).
 *
 * The extraction is deliberately literal: it looks for the phrases people
 * actually say when they close something ("decision:", "we agreed", "we're
 * going with") and proposes the sentence around each one, with the lines
 * that preceded it as context and the raw excerpt attached as evidence.
 * Nothing is inferred and nothing is summarised — a proposal that quietly
 * invented an outcome would be worse than no proposal at all, which is
 * exactly why every result lands as a draft that a human confirms.
 */
class ProposeDecisionsFromTranscript
{
    /**
     * How many drafts one transcript may propose. A two-hour call would
     * otherwise bury the ledger it is meant to fill.
     */
    public const int MAX_PROPOSALS = 12;

    /**
     * How many preceding lines travel with a proposal as its context.
     */
    private const int CONTEXT_LINES = 3;

    /**
     * The ceiling the decision form itself enforces on the two fields a
     * human edits. A transcript is allowed to be far longer than one record,
     * so a single enormous line has to be cut — a draft nobody can save
     * because its own extraction overflowed validation is worse than a
     * shortened one.
     *
     * The excerpt is deliberately exempt: it is the evidence, it is never
     * re-submitted through a form, and its size is already bounded by the
     * limit on the transcript it came from. Trimming it would quietly
     * destroy the very thing a reader checks the proposal against.
     */
    private const int MAX_EDITABLE_TEXT = 5000;

    /**
     * Lines that announce a decision outright, whatever follows the marker.
     */
    private const string MARKER_PREFIX = '/^(decision|decisions|decided|agreed|conclusion)\s*[:\x{2013}\x{2014}-]\s*(.+)$/iu';

    /**
     * Phrases that close something mid-sentence.
     */
    private const string MARKER_PHRASE = "/\b(?:we|they|the team|the client|it)\s*(?:'ve|’ve|\s+have|\s+has|\s+was|\s+were)?\s*(?:decided|agreed|settled\s+on)\b|\bthe\s+decision\s+(?:is|was)\b|\bwe(?:'re|’re|\s+are)\s+going\s+with\b|\blet'?s\s+go\s+with\b/iu";

    /**
     * A speaker turn: an optional timestamp, a name, a colon, the utterance.
     */
    private const string SPEAKER_LINE = "/^\s*(?:\[[^\]]{0,40}\]\s*|\(?\d{1,2}:\d{2}(?::\d{2})?\)?\s*)?([\p{L}][\p{L}\p{M}\s.'’-]{0,60}?)\s*:\s*(.+)$/u";

    /**
     * Propose decision drafts from the transcript, as attribute arrays ready
     * for Engagement::recordDecision().
     *
     * @return list<array<string, mixed>>
     */
    public function __invoke(string $transcript): array
    {
        $lines = $this->lines($transcript);
        $participants = $this->participants($lines);
        $proposals = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $utterance = $this->utterance($line);
            $sentence = $this->decisionSentence($utterance);

            if ($sentence === null) {
                continue;
            }

            $fingerprint = Str::lower(preg_replace('/[^\p{L}\p{N}]+/u', '', $sentence) ?? $sentence);

            if ($fingerprint === '' || isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;

            $excerpt = $this->excerpt($lines, $index);

            $proposals[] = [
                'source' => DecisionSource::Transcript,
                'title' => Str::limit(Str::ucfirst($sentence), 120),
                'context' => $this->capped($excerpt),
                'decision' => $this->capped($sentence),
                'participants' => $participants,
                'transcript_excerpt' => $excerpt,
            ];

            if (count($proposals) === self::MAX_PROPOSALS) {
                break;
            }
        }

        return $proposals;
    }

    /**
     * Text cut to the ceiling the decision form enforces, ellipsis included
     * in the count — an extraction that overflows its own validation leaves
     * a draft nobody can save. The untrimmed original survives on the
     * record's excerpt.
     */
    private function capped(string $text): string
    {
        return Str::limit($text, self::MAX_EDITABLE_TEXT - 1, '…');
    }

    /**
     * The transcript as trimmed, non-empty lines.
     *
     * @return list<string>
     */
    private function lines(string $transcript): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $transcript) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * Everyone who spoke, in the order they first did — the room, as far as
     * the transcript can tell. Affiliation is left to the reader: a name in
     * a transcript says nothing about which side of the table it sat on.
     *
     * @param  list<string>  $lines
     * @return list<array{name: string, affiliation: null}>
     */
    private function participants(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            if (preg_match(self::MARKER_PREFIX, $line) === 1) {
                continue;
            }

            if (preg_match(self::SPEAKER_LINE, $line, $matches) !== 1) {
                continue;
            }

            $name = trim($matches[1]);

            if ($name === '' || Str::length($name) > 60) {
                continue;
            }

            $names[Str::lower($name)] = $name;
        }

        return array_values(array_map(
            fn (string $name): array => ['name' => $name, 'affiliation' => null],
            array_slice($names, 0, 20, preserve_keys: true),
        ));
    }

    /**
     * The spoken part of a line, with the speaker's name stripped.
     */
    private function utterance(string $line): string
    {
        if (preg_match(self::MARKER_PREFIX, $line) === 1) {
            return $line;
        }

        return preg_match(self::SPEAKER_LINE, $line, $matches) === 1 ? trim($matches[2]) : $line;
    }

    /**
     * The sentence in this utterance that closes something, if any. A marked
     * line contributes what follows its marker; otherwise the sentence the
     * phrase sits in is proposed whole, because the surrounding clause is
     * usually where the actual outcome lives.
     */
    private function decisionSentence(string $utterance): ?string
    {
        if (preg_match(self::MARKER_PREFIX, $utterance, $matches) === 1) {
            $sentence = trim($matches[2]);

            return $sentence === '' ? null : $sentence;
        }

        foreach (preg_split('/(?<=[.!?])\s+/u', $utterance) ?: [] as $sentence) {
            $sentence = trim($sentence);

            if ($sentence !== '' && preg_match(self::MARKER_PHRASE, $sentence) === 1) {
                return $sentence;
            }
        }

        return null;
    }

    /**
     * The proposed line together with the few that led up to it — the raw
     * evidence a reader needs to judge whether the proposal is right.
     *
     * @param  list<string>  $lines
     */
    private function excerpt(array $lines, int $index): string
    {
        $start = max(0, $index - self::CONTEXT_LINES);

        return implode("\n", array_slice($lines, $start, $index - $start + 1));
    }
}
