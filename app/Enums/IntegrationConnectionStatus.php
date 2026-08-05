<?php

namespace App\Enums;

/**
 * Whether an integration is currently syncing. Disconnecting never deletes
 * the connection or the work it imported — history is retained and a later
 * reconnect resyncs against the same record (FA-7).
 */
enum IntegrationConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
        };
    }
}
