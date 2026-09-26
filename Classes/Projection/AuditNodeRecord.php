<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Projection;

use Neos\Flow\Annotations as Flow;

/**
 * A node aggregate as known to the audit index (also contains removed nodes)
 */
#[Flow\Proxy(false)]
final readonly class AuditNodeRecord
{
    public function __construct(
        public string $nodeAggregateId,
        public ?string $parentNodeAggregateId,
        public ?string $siteNodeAggregateId,
        public string $nodeTypeName,
        public ?string $nodeName,
        public ?string $title,
        public bool $removed,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            (string)(is_scalar($row['nodeaggregateid']) ? $row['nodeaggregateid'] : ''),
            is_string($row['parentnodeaggregateid']) ? $row['parentnodeaggregateid'] : null,
            is_string($row['sitenodeaggregateid']) ? $row['sitenodeaggregateid'] : null,
            (string)(is_scalar($row['nodetypename']) ? $row['nodetypename'] : ''),
            is_string($row['nodename']) ? $row['nodename'] : null,
            is_string($row['title']) ? $row['title'] : null,
            (bool)$row['removed'],
        );
    }

    public function getLabel(): string
    {
        return $this->getTitleOrName() ?? $this->nodeAggregateId;
    }

    /**
     * Human-readable name, if the node has one (content nodes often have neither title nor node name)
     */
    public function getTitleOrName(): ?string
    {
        return $this->title ?? $this->nodeName;
    }
}
