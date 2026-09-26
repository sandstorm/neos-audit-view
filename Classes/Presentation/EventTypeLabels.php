<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Presentation;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;

/**
 * Editor-friendly labels for event types (translations "eventType.*" in Modules.xlf).
 *
 * The most specific translation wins, falling back to more generic ones and finally to the raw event type:
 *   eventType.<type>.<variant>.<nodeKind>  e.g. eventType.SubtreeWasTagged.removed.document
 *   eventType.<type>.<variant>             e.g. eventType.WorkspaceWasPublished.partial
 *   eventType.<type>.<nodeKind>            e.g. eventType.NodeAggregateWasRemoved.content
 *   eventType.<type>                       e.g. eventType.NodeAggregateWasRemoved
 */
#[Flow\Scope('singleton')]
final class EventTypeLabels
{
    #[Flow\Inject]
    protected Translator $translator;

    /**
     * Label for a concrete event
     *
     * @param 'document'|'content'|null $nodeKind
     */
    public function forEvent(string $eventType, string $payload, ?string $nodeKind): string
    {
        $variant = self::determineVariant($eventType, $payload);
        $candidates = array_filter([
            $variant !== null && $nodeKind !== null ? "{$eventType}.{$variant}.{$nodeKind}" : null,
            $variant !== null ? "{$eventType}.{$variant}" : null,
            $nodeKind !== null ? "{$eventType}.{$nodeKind}" : null,
            $eventType,
        ]);
        foreach ($candidates as $candidate) {
            $label = $this->translator->translateById('eventType.' . $candidate, [], null, null, 'Modules', 'Sandstorm.NeosAuditView');
            if ($label !== null) {
                return $label;
            }
        }
        return $eventType;
    }

    /**
     * Generic label for an event type, e.g. for the event type filter
     */
    public function forEventType(string $eventType): string
    {
        return $this->translator->translateById('eventType.' . $eventType, [], null, null, 'Modules', 'Sandstorm.NeosAuditView')
            ?? $eventType;
    }

    /**
     * Distinguishes events which mean different things depending on their payload:
     * subtree tags ("disabled" = hidden, "removed" = soft deleted) and partial publishing
     */
    private static function determineVariant(string $eventType, string $payload): ?string
    {
        if (!in_array($eventType, ['SubtreeWasTagged', 'SubtreeWasUntagged', 'WorkspaceWasPublished'], true)) {
            return null;
        }
        try {
            $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decodedPayload)) {
            return null;
        }
        if ($eventType === 'WorkspaceWasPublished') {
            return ($decodedPayload['partial'] ?? false) === true ? 'partial' : null;
        }
        $tag = $decodedPayload['tag'] ?? null;
        return is_string($tag) && preg_match('/^[a-zA-Z0-9_-]+$/', $tag) === 1 ? $tag : null;
    }
}
