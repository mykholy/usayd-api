<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Record an append-only, immutable audit event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function log(
        string $action,
        string $subjectType,
        string|int $subjectId,
        ?string $purpose = null,
        array $metadata = [],
        ?Request $request = null
    ): AuditEvent {
        $req = $request ?? request();
        $traceId = (string) ($req?->attributes->get('traceId') ?? Str::uuid());
        $actorId = $req?->user()?->id;
        $actorType = $req?->user() ? get_class($req->user()) : 'system';
        $ipHash = $req?->ip() ? hash('sha256', $req->ip().config('app.key')) : null;

        return AuditEvent::create([
            'trace_id' => $traceId,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'action' => $action,
            'purpose' => $purpose ?? 'Unspecified business purpose',
            'ip_hash' => $ipHash,
            'metadata' => $metadata,
        ]);
    }
}
