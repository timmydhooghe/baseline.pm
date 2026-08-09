<?php

namespace App\Models\Concerns;

use App\Actions\Governance\GovernanceRecordLabel;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders a linked governance record as the chip the ledgers display,
 * without every reading page having to know six model shapes. Deleted or
 * inaccessible targets degrade to a placeholder rather than blowing up a
 * record view — the link is history and the record it named may be gone.
 */
trait DescribesLinkedRecord
{
    /**
     * @return array{type: string, type_label: string, id: string, title: string}
     */
    public function describe(): array
    {
        $record = $this->linkedRecord();

        return [
            'type' => $this->linkedRecordType(),
            'type_label' => GovernanceRecordLabel::type($record, $this->linkedRecordType()),
            'id' => $this->linkedRecordId(),
            'title' => GovernanceRecordLabel::title($record),
        ];
    }

    /**
     * The record this link points at, or null when it no longer exists.
     */
    abstract public function linkedRecord(): ?Model;

    /**
     * The morph class of the linked record.
     */
    abstract public function linkedRecordType(): string;

    /**
     * The key of the linked record.
     */
    abstract public function linkedRecordId(): string;
}
