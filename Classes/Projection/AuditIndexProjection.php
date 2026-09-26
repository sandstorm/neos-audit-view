<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Projection;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Neos\ContentRepository\Core\DimensionSpace\AbstractDimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePointSet;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\EventStore\InitiatingEventMetadata;
use Neos\ContentRepository\Core\Feature\Common\EmbedsContentStreamId;
use Neos\ContentRepository\Core\Feature\Common\EmbedsNodeAggregateId;
use Neos\ContentRepository\Core\Feature\Common\EmbedsWorkspaceName;
use Neos\ContentRepository\Core\Feature\Common\InterdimensionalSibling;
use Neos\ContentRepository\Core\Feature\Common\InterdimensionalSiblings;
use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeMove\Event\NodeAggregateWasMoved;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Event\RootNodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\RootWorkspaceWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\WorkspaceWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Event\WorkspaceBaseWorkspaceWasChanged;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event\WorkspaceWasRebased;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Dbal\DbalSchemaDiff;
use Neos\ContentRepository\Dbal\DbalSchemaFactory;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

/**
 * Maintains a lookup index over all content repository events, so the audit trail module can filter
 * events by site, workspace, user and node without scanning the event payloads.
 *
 * Tables (MySQL/MariaDB):
 * - <prefix>_event:         one row per event (sequencenumber = event store sequence number)
 * - <prefix>_node:          node aggregate -> parent, site, node type, node name, last known title (kept after removal)
 * - <prefix>_contentstream: content stream -> workspace, for events only carrying a content stream id
 * - <prefix>_event_dsp:     event -> dimension space points it affects (1:n)
 *
 * @implements ProjectionInterface<AuditIndexFinder>
 */
final class AuditIndexProjection implements ProjectionInterface
{
    private const TITLE_PROPERTY = 'title';
    private const TITLE_MAX_LENGTH = 255;

    private AuditIndexFinder $finder;

    public function __construct(
        private readonly Connection $dbal,
        private readonly string $tableNamePrefix,
        string $eventTableName,
    ) {
        $this->finder = new AuditIndexFinder($this->dbal, $this->tableNamePrefix, $eventTableName);
    }

    public function setUp(): void
    {
        foreach ($this->determineRequiredSqlStatements() as $statement) {
            $this->dbal->executeStatement($statement);
        }
    }

    public function status(): ProjectionStatus
    {
        try {
            $this->dbal->connect();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(sprintf('Failed to connect to database: %s', $e->getMessage()));
        }
        try {
            $requiredSqlStatements = $this->determineRequiredSqlStatements();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(sprintf('Failed to determine required SQL statements: %s', $e->getMessage()));
        }
        if ($requiredSqlStatements !== []) {
            return ProjectionStatus::setupRequired(sprintf('The following SQL statement%s required: %s', count($requiredSqlStatements) !== 1 ? 's are' : ' is', implode(chr(10), $requiredSqlStatements)));
        }
        return ProjectionStatus::ok();
    }

    /**
     * @return array<string>
     */
    private function determineRequiredSqlStatements(): array
    {
        $platform = $this->dbal->getDatabasePlatform();

        $eventTable = new Table($this->tableNamePrefix . '_event', [
            (new Column('sequencenumber', Type::getType(Types::BIGINT)))->setUnsigned(true)->setNotnull(true),
            (new Column('recordedat', Type::getType(Types::DATETIME_IMMUTABLE)))->setNotnull(true),
            self::stringColumn('eventtype', 100, $platform)->setNotnull(true),
            self::stringColumn('workspacename', WorkspaceName::MAX_LENGTH, $platform)->setNotnull(false),
            self::stringColumn('contentstreamid', 36, $platform)->setNotnull(false),
            self::stringColumn('userid', 64, $platform)->setNotnull(false),
            self::stringColumn('nodeaggregateid', 64, $platform)->setNotnull(false),
            self::stringColumn('sitenodeaggregateid', 64, $platform)->setNotnull(false),
        ]);
        $eventTable->setPrimaryKey(['sequencenumber']);
        $eventTable->addIndex(['workspacename', 'sequencenumber'], 'workspace');
        $eventTable->addIndex(['userid', 'sequencenumber'], 'user');
        $eventTable->addIndex(['nodeaggregateid', 'sequencenumber'], 'node');
        $eventTable->addIndex(['sitenodeaggregateid', 'sequencenumber'], 'site');
        $eventTable->addIndex(['eventtype', 'sequencenumber'], 'eventtype');
        $eventTable->addIndex(['recordedat', 'sequencenumber'], 'recordedat');

        $nodeTable = new Table($this->tableNamePrefix . '_node', [
            self::stringColumn('nodeaggregateid', 64, $platform)->setNotnull(true),
            self::stringColumn('parentnodeaggregateid', 64, $platform)->setNotnull(false),
            self::stringColumn('sitenodeaggregateid', 64, $platform)->setNotnull(false),
            self::stringColumn('nodetypename', 255, $platform)->setNotnull(true),
            self::stringColumn('nodename', 255, $platform)->setNotnull(false),
            self::stringColumn('title', self::TITLE_MAX_LENGTH, $platform)->setNotnull(false),
            (new Column('removed', Type::getType(Types::BOOLEAN)))->setNotnull(true)->setDefault(false),
        ]);
        $nodeTable->setPrimaryKey(['nodeaggregateid']);
        $nodeTable->addIndex(['parentnodeaggregateid'], 'parent');
        $nodeTable->addIndex(['title'], 'title');

        $contentStreamTable = new Table($this->tableNamePrefix . '_contentstream', [
            self::stringColumn('contentstreamid', 36, $platform)->setNotnull(true),
            self::stringColumn('workspacename', WorkspaceName::MAX_LENGTH, $platform)->setNotnull(true),
        ]);
        $contentStreamTable->setPrimaryKey(['contentstreamid']);

        $eventDimensionSpacePointTable = new Table($this->tableNamePrefix . '_event_dsp', [
            (new Column('sequencenumber', Type::getType(Types::BIGINT)))->setUnsigned(true)->setNotnull(true),
            self::stringColumn('dimensionspacepointhash', 32, $platform)->setNotnull(true),
        ]);
        $eventDimensionSpacePointTable->setPrimaryKey(['sequencenumber', 'dimensionspacepointhash']);
        $eventDimensionSpacePointTable->addIndex(['dimensionspacepointhash', 'sequencenumber'], 'dimensionspacepoint');

        $schema = DbalSchemaFactory::createSchemaWithTables($this->dbal, [$eventTable, $nodeTable, $contentStreamTable, $eventDimensionSpacePointTable]);
        return DbalSchemaDiff::determineRequiredSqlStatements($this->dbal, $schema);
    }

    /**
     * All string columns are utf8mb4 (unlike the core id columns, which are ascii), so any user input can be
     * compared against them without collation errors
     */
    private static function stringColumn(string $columnName, int $length, AbstractPlatform $platform): Column
    {
        return DbalSchemaFactory::columnForGenericString($columnName, $platform)->setLength($length);
    }

    public function resetState(): void
    {
        foreach (['_event', '_node', '_contentstream', '_event_dsp'] as $suffix) {
            $this->dbal->executeStatement('TRUNCATE ' . $this->tableNamePrefix . $suffix);
        }
    }

    public function getState(): AuditIndexFinder
    {
        return $this->finder;
    }

    public function apply(EventInterface $event, EventEnvelope $eventEnvelope): void
    {
        // 1. keep the lookup tables up to date, so the event row below can already be resolved
        if ($event instanceof RootNodeAggregateWithNodeWasCreated) {
            $this->upsertNode($event->nodeAggregateId->value, null, null, $event->nodeTypeName->value, null, null);
        } elseif ($event instanceof NodeAggregateWithNodeWasCreated) {
            $this->whenNodeAggregateWithNodeWasCreated($event);
        } elseif ($event instanceof NodePropertiesWereSet) {
            $this->whenNodePropertiesWereSet($event);
        } elseif ($event instanceof NodeAggregateWasMoved) {
            $this->whenNodeAggregateWasMoved($event);
        } elseif ($event instanceof NodeAggregateWasRemoved) {
            $this->dbal->update(
                $this->tableNamePrefix . '_node',
                ['removed' => 1],
                ['nodeaggregateid' => $event->nodeAggregateId->value],
            );
        } elseif (
            $event instanceof RootWorkspaceWasCreated
            || $event instanceof WorkspaceWasCreated
            || $event instanceof WorkspaceWasDiscarded
            || $event instanceof WorkspaceWasRebased
            || $event instanceof WorkspaceBaseWorkspaceWasChanged
        ) {
            $this->upsertContentStream($event->newContentStreamId->value, $event->workspaceName->value);
        } elseif ($event instanceof WorkspaceWasPublished) {
            $this->upsertContentStream($event->newSourceContentStreamId->value, $event->sourceWorkspaceName->value);
        }

        // 2. index the event itself
        $contentStreamId = $event instanceof EmbedsContentStreamId ? $event->getContentStreamId()->value : null;
        $workspaceName = $event instanceof EmbedsWorkspaceName ? $event->getWorkspaceName()->value : null;
        if ($workspaceName === null && $contentStreamId !== null) {
            $workspaceName = $this->findWorkspaceNameForContentStream($contentStreamId);
        }
        $nodeAggregateId = $event instanceof EmbedsNodeAggregateId ? $event->getNodeAggregateId()->value : null;

        $userId = $eventEnvelope->event->metadata?->get(InitiatingEventMetadata::INITIATING_USER_ID);

        $this->dbal->insert($this->tableNamePrefix . '_event', [
            'sequencenumber' => $eventEnvelope->sequenceNumber->value,
            'recordedat' => $eventEnvelope->recordedAt->format('Y-m-d H:i:s'),
            'eventtype' => $eventEnvelope->event->type->value,
            'workspacename' => $workspaceName,
            'contentstreamid' => $contentStreamId,
            'userid' => is_string($userId) ? $userId : null,
            'nodeaggregateid' => $nodeAggregateId,
            'sitenodeaggregateid' => $nodeAggregateId !== null ? $this->findSiteForNode($nodeAggregateId) : null,
        ]);
        foreach (self::extractDimensionSpacePointHashes($event) as $dimensionSpacePointHash) {
            $this->dbal->insert($this->tableNamePrefix . '_event_dsp', [
                'sequencenumber' => $eventEnvelope->sequenceNumber->value,
                'dimensionspacepointhash' => $dimensionSpacePointHash,
            ]);
        }
    }

    /**
     * All dimension space points the event refers to (origin, affected/covered points, variant sources and targets,
     * sibling coverage), found generically via the event's public properties
     *
     * @return list<string> unique dimension space point hashes
     */
    private static function extractDimensionSpacePointHashes(EventInterface $event): array
    {
        $hashes = [];
        foreach (get_object_vars($event) as $propertyValue) {
            $dimensionSpacePoints = match (true) {
                // covers DimensionSpacePoint and OriginDimensionSpacePoint; both hash the sorted coordinates
                $propertyValue instanceof AbstractDimensionSpacePoint => [$propertyValue],
                $propertyValue instanceof DimensionSpacePointSet => $propertyValue->points,
                $propertyValue instanceof OriginDimensionSpacePointSet => iterator_to_array($propertyValue),
                $propertyValue instanceof InterdimensionalSiblings => array_map(
                    fn (InterdimensionalSibling $sibling) => $sibling->dimensionSpacePoint,
                    $propertyValue->items
                ),
                default => [],
            };
            foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
                $hashes[$dimensionSpacePoint->hash] = true;
            }
        }
        return array_keys($hashes);
    }

    private function whenNodeAggregateWithNodeWasCreated(NodeAggregateWithNodeWasCreated $event): void
    {
        $parent = $this->findNode($event->parentNodeAggregateId->value);
        $siteNodeAggregateId = match (true) {
            $parent === null => null,
            $parent['nodetypename'] === NodeTypeNameFactory::NAME_SITES => $event->nodeAggregateId->value,
            default => $parent['sitenodeaggregateid'],
        };
        $this->upsertNode(
            $event->nodeAggregateId->value,
            $event->parentNodeAggregateId->value,
            $siteNodeAggregateId,
            $event->nodeTypeName->value,
            $event->nodeName?->value,
            self::extractTitle($event->initialPropertyValues),
        );
    }

    private function whenNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
        $title = self::extractTitle($event->propertyValues);
        if ($title === null) {
            return;
        }
        $this->dbal->update(
            $this->tableNamePrefix . '_node',
            ['title' => $title],
            ['nodeaggregateid' => $event->nodeAggregateId->value],
        );
    }

    private function whenNodeAggregateWasMoved(NodeAggregateWasMoved $event): void
    {
        if ($event->newParentNodeAggregateId === null) {
            // only the sibling position changed
            return;
        }
        $node = $this->findNode($event->nodeAggregateId->value);
        $newParent = $this->findNode($event->newParentNodeAggregateId->value);
        if ($node === null || $newParent === null) {
            return;
        }
        $this->dbal->update(
            $this->tableNamePrefix . '_node',
            ['parentnodeaggregateid' => $event->newParentNodeAggregateId->value],
            ['nodeaggregateid' => $event->nodeAggregateId->value],
        );
        $newSiteNodeAggregateId = $newParent['sitenodeaggregateid'];
        if ($newSiteNodeAggregateId === $node['sitenodeaggregateid']) {
            return;
        }
        // moved to another site: re-assign the whole subtree
        $nodeTable = $this->tableNamePrefix . '_node';
        $subtreeIds = $this->dbal->fetchFirstColumn(
            <<<SQL
                WITH RECURSIVE subtree (nodeaggregateid) AS (
                    SELECT :nodeAggregateId
                    UNION ALL
                    SELECT n.nodeaggregateid FROM {$nodeTable} n
                    JOIN subtree s ON n.parentnodeaggregateid = s.nodeaggregateid
                )
                SELECT nodeaggregateid FROM subtree
            SQL,
            ['nodeAggregateId' => $event->nodeAggregateId->value],
        );
        foreach (array_chunk($subtreeIds, 500) as $chunk) {
            $this->dbal->executeStatement(
                "UPDATE {$nodeTable} SET sitenodeaggregateid = :site WHERE nodeaggregateid IN (:ids)",
                ['site' => $newSiteNodeAggregateId, 'ids' => $chunk],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }

    private function upsertNode(string $nodeAggregateId, ?string $parentNodeAggregateId, ?string $siteNodeAggregateId, string $nodeTypeName, ?string $nodeName, ?string $title): void
    {
        // a node aggregate is created once per content stream (e.g. user workspace, then again on publish to live)
        $this->dbal->executeStatement(
            <<<SQL
                INSERT INTO {$this->tableNamePrefix}_node
                    (nodeaggregateid, parentnodeaggregateid, sitenodeaggregateid, nodetypename, nodename, title, removed)
                VALUES (:nodeAggregateId, :parent, :site, :nodeTypeName, :nodeName, :title, 0)
                ON DUPLICATE KEY UPDATE
                    parentnodeaggregateid = VALUES(parentnodeaggregateid),
                    sitenodeaggregateid = VALUES(sitenodeaggregateid),
                    nodetypename = VALUES(nodetypename),
                    nodename = VALUES(nodename),
                    title = COALESCE(VALUES(title), title),
                    removed = 0
            SQL,
            [
                'nodeAggregateId' => $nodeAggregateId,
                'parent' => $parentNodeAggregateId,
                'site' => $siteNodeAggregateId,
                'nodeTypeName' => $nodeTypeName,
                'nodeName' => $nodeName,
                'title' => $title,
            ],
        );
    }

    private function upsertContentStream(string $contentStreamId, string $workspaceName): void
    {
        $this->dbal->executeStatement(
            <<<SQL
                INSERT INTO {$this->tableNamePrefix}_contentstream (contentstreamid, workspacename)
                VALUES (:contentStreamId, :workspaceName)
                ON DUPLICATE KEY UPDATE workspacename = VALUES(workspacename)
            SQL,
            ['contentStreamId' => $contentStreamId, 'workspaceName' => $workspaceName],
        );
    }

    /**
     * @return array{nodetypename: string, sitenodeaggregateid: ?string}|null
     */
    private function findNode(string $nodeAggregateId): ?array
    {
        $row = $this->dbal->fetchAssociative(
            "SELECT nodetypename, sitenodeaggregateid FROM {$this->tableNamePrefix}_node WHERE nodeaggregateid = :id",
            ['id' => $nodeAggregateId],
        );
        if ($row === false) {
            return null;
        }
        return [
            'nodetypename' => is_string($row['nodetypename']) ? $row['nodetypename'] : '',
            'sitenodeaggregateid' => is_string($row['sitenodeaggregateid']) ? $row['sitenodeaggregateid'] : null,
        ];
    }

    private function findSiteForNode(string $nodeAggregateId): ?string
    {
        return $this->findNode($nodeAggregateId)['sitenodeaggregateid'] ?? null;
    }

    private function findWorkspaceNameForContentStream(string $contentStreamId): ?string
    {
        $workspaceName = $this->dbal->fetchOne(
            "SELECT workspacename FROM {$this->tableNamePrefix}_contentstream WHERE contentstreamid = :id",
            ['id' => $contentStreamId],
        );
        return is_string($workspaceName) ? $workspaceName : null;
    }

    private static function extractTitle(SerializedPropertyValues $propertyValues): ?string
    {
        $value = $propertyValues->getProperty(self::TITLE_PROPERTY)?->value;
        if (!is_string($value)) {
            return null;
        }
        $title = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
        return $title === '' ? null : mb_substr($title, 0, self::TITLE_MAX_LENGTH);
    }
}
