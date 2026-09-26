<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Projection;

use Neos\Flow\Annotations as Flow;

/**
 * Filters for {@see AuditIndexFinder::findEvents()}; all filters are optional and AND-combined.
 */
#[Flow\Proxy(false)]
final readonly class AuditEventQuery
{
    /**
     * @param list<string>|null $nodeAggregateIds null = no node filter; empty list = matches nothing
     * @param list<string>|null $eventTypes null = no event type filter
     * @param list<string> $excludedEventTypes event types to leave out (only applied without $eventTypes)
     * @param list<string>|null $dimensionSpacePointHashes null = no dimension filter; empty list = matches nothing
     * @param int|null $beforeSequenceNumber only events older than this (cursor for paging)
     * @param int|null $afterSequenceNumber only events newer than this
     * @param \DateTimeImmutable|null $recordedBefore only events recorded before this point in time
     * @param bool $oldestFirst sort order; newest first by default
     */
    public function __construct(
        public ?string $siteNodeAggregateId = null,
        public ?string $workspaceName = null,
        public ?string $userId = null,
        public ?array $nodeAggregateIds = null,
        public ?array $eventTypes = null,
        public array $excludedEventTypes = [],
        public ?array $dimensionSpacePointHashes = null,
        public ?int $beforeSequenceNumber = null,
        public int $limit = 50,
        public ?int $afterSequenceNumber = null,
        public ?\DateTimeImmutable $recordedBefore = null,
        public bool $oldestFirst = false,
    ) {
    }

    /**
     * The same filters with another range / sort order / limit
     */
    public function withRange(
        int $limit,
        ?int $beforeSequenceNumber = null,
        ?int $afterSequenceNumber = null,
        ?\DateTimeImmutable $recordedBefore = null,
        bool $oldestFirst = false,
    ): self {
        return new self(
            $this->siteNodeAggregateId,
            $this->workspaceName,
            $this->userId,
            $this->nodeAggregateIds,
            $this->eventTypes,
            $this->excludedEventTypes,
            $this->dimensionSpacePointHashes,
            $beforeSequenceNumber,
            $limit,
            $afterSequenceNumber,
            $recordedBefore,
            $oldestFirst,
        );
    }
}
