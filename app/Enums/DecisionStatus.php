<?php

namespace App\Enums;

/**
 * Where a decision record stands (FA-18). Drafts are proposals — raised by
 * hand or extracted from a meeting transcript — and carry no governance
 * weight until confirmed. Confirmed records are the ledger; they are never
 * rewritten, only superseded by a later decision that names them.
 */
enum DecisionStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Superseded => 'Superseded',
        };
    }

    /**
     * Whether the record is still being written. A confirmed decision is
     * governance history — corrections arrive as a superseding record.
     */
    public function acceptsEdits(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the record counts as a decision anyone can rely on: confirmed
     * ones do, superseded ones did until the record that replaced them.
     */
    public function isConfirmed(): bool
    {
        return in_array($this, [self::Confirmed, self::Superseded], true);
    }
}
