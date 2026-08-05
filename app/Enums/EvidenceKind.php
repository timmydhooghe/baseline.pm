<?php

namespace App\Enums;

/**
 * What a piece of deliverable evidence is (FA-22): releases, demos, test
 * reports and documents that back progress claims and acceptance criteria.
 */
enum EvidenceKind: string
{
    case Release = 'release';
    case Demo = 'demo';
    case TestReport = 'test_report';
    case Document = 'document';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Release => 'Release',
            self::Demo => 'Demo',
            self::TestReport => 'Test report',
            self::Document => 'Document',
            self::Other => 'Other',
        };
    }
}
