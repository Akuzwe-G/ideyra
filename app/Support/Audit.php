<?php

namespace App\Support;

use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function record(string $action, Model $subject, ?int $institutionId = null, array $metadata = [], ?int $actorId = null): void
    {
        AuditEntry::create([
            'actor_id' => $actorId ?? auth()->id(), 'institution_id' => $institutionId,
            'action' => $action, 'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(),
            'metadata' => $metadata,
        ]);
        WorkspaceNotifications::record($action, $subject, $institutionId, $metadata);
    }
}
