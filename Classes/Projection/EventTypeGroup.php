<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Projection;

/**
 * Category of an event type, derived from its name (e.g. "NodePropertiesWereSet" => node)
 */
enum EventTypeGroup: string
{
    case NODE = 'node';
    case WORKSPACE = 'workspace';
    case CONTENT_STREAM = 'contentStream';
    case OTHER = 'other';

    private const PREFIXES = [
        'Node' => self::NODE,
        'RootNode' => self::NODE,
        'Subtree' => self::NODE,
        'Workspace' => self::WORKSPACE,
        'RootWorkspace' => self::WORKSPACE,
        'ContentStream' => self::CONTENT_STREAM,
    ];

    /**
     * Order of event types in the event type filter, by meaning (not by label, so it's the same in every language).
     * Event types not listed here follow in alphabetical order of their label.
     */
    public const DISPLAY_ORDER = [
        // content changes
        'NodeAggregateWithNodeWasCreated',
        'NodeAggregateWasRemoved',
        'NodePropertiesWereSet',
        'NodeReferencesWereSet',
        'NodeAggregateWasMoved',
        'SubtreeWasTagged',
        'SubtreeWasUntagged',
        'NodeSpecializationVariantWasCreated',
        'NodeGeneralizationVariantWasCreated',
        'NodePeerVariantWasCreated',
        'RootNodeAggregateWithNodeWasCreated',
        // publishing & workspaces
        'WorkspaceWasPublished',
        'WorkspaceWasDiscarded',
        'WorkspaceWasRebased',
        'WorkspaceWasCreated',
        'RootWorkspaceWasCreated',
    ];

    /**
     * Position of the event type in {@see self::DISPLAY_ORDER}; unlisted types sort last
     */
    public static function displayPosition(string $eventType): int
    {
        $position = array_search($eventType, self::DISPLAY_ORDER, true);
        return $position === false ? PHP_INT_MAX : $position;
    }

    /**
     * Technical events which are not interesting for editors are only shown when explicitly selected
     */
    public function isHiddenByDefault(): bool
    {
        return $this === self::CONTENT_STREAM;
    }

    public static function fromEventType(string $eventType): self
    {
        foreach (self::PREFIXES as $prefix => $group) {
            if (str_starts_with($eventType, $prefix)) {
                return $group;
            }
        }
        return self::OTHER;
    }
}
