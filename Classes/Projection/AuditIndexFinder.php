<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Projection;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Read side of the {@see AuditIndexProjection}
 */
#[Flow\Proxy(false)]
final class AuditIndexFinder implements ProjectionStateInterface
{
    public function __construct(
        private readonly Connection $dbal,
        private readonly string $tableNamePrefix,
        private readonly string $eventTableName,
    ) {
    }

    /**
     * Events matching the query, newest first (oldest first with $query->oldestFirst). Returns up to
     * $query->limit + 1 records; the extra record signals that there are more events in that direction.
     *
     * @return list<AuditEventRecord>
     */
    public function findEvents(AuditEventQuery $query): array
    {
        if ($query->nodeAggregateIds === [] || $query->dimensionSpacePointHashes === []) {
            return [];
        }
        $queryBuilder = $this->dbal->createQueryBuilder()
            ->select(
                'i.sequencenumber, i.recordedat, i.eventtype, i.workspacename, i.contentstreamid, i.userid,'
                . ' i.nodeaggregateid, i.sitenodeaggregateid,'
                . ' e.id, e.stream, e.payload, e.metadata, e.correlationid, e.causationid'
            )
            ->from($this->tableNamePrefix . '_event', 'i')
            ->innerJoin('i', $this->eventTableName, 'e', 'e.sequencenumber = i.sequencenumber')
            ->orderBy('i.sequencenumber', $query->oldestFirst ? 'ASC' : 'DESC')
            ->setMaxResults($query->limit + 1);

        if ($query->siteNodeAggregateId !== null) {
            $queryBuilder->andWhere('i.sitenodeaggregateid = :site')->setParameter('site', $query->siteNodeAggregateId);
        }
        if ($query->workspaceName !== null) {
            $queryBuilder->andWhere('i.workspacename = :workspace')->setParameter('workspace', $query->workspaceName);
        }
        if ($query->userId !== null) {
            $queryBuilder->andWhere('i.userid = :user')->setParameter('user', $query->userId);
        }
        if ($query->nodeAggregateIds !== null) {
            $queryBuilder->andWhere('i.nodeaggregateid IN (:nodes)')->setParameter('nodes', $query->nodeAggregateIds, ArrayParameterType::STRING);
        }
        if ($query->eventTypes !== null && $query->eventTypes !== []) {
            $queryBuilder->andWhere('i.eventtype IN (:eventTypes)')->setParameter('eventTypes', $query->eventTypes, ArrayParameterType::STRING);
        } elseif ($query->excludedEventTypes !== []) {
            $queryBuilder->andWhere('i.eventtype NOT IN (:excludedEventTypes)')->setParameter('excludedEventTypes', $query->excludedEventTypes, ArrayParameterType::STRING);
        }
        if ($query->dimensionSpacePointHashes !== null) {
            $queryBuilder
                ->andWhere(
                    "EXISTS (SELECT 1 FROM {$this->tableNamePrefix}_event_dsp d"
                    . ' WHERE d.sequencenumber = i.sequencenumber AND d.dimensionspacepointhash IN (:dimensionSpacePointHashes))'
                )
                ->setParameter('dimensionSpacePointHashes', $query->dimensionSpacePointHashes, ArrayParameterType::STRING);
        }
        if ($query->beforeSequenceNumber !== null) {
            $queryBuilder->andWhere('i.sequencenumber < :before')->setParameter('before', $query->beforeSequenceNumber);
        }
        if ($query->afterSequenceNumber !== null) {
            $queryBuilder->andWhere('i.sequencenumber > :after')->setParameter('after', $query->afterSequenceNumber);
        }
        if ($query->recordedBefore !== null) {
            $queryBuilder->andWhere('i.recordedat < :recordedBefore')->setParameter(
                'recordedBefore',
                $query->recordedBefore->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            );
        }

        return array_map(
            AuditEventRecord::fromDatabaseRow(...),
            $queryBuilder->executeQuery()->fetchAllAssociative()
        );
    }

    public function findNodeById(string $nodeAggregateId): ?AuditNodeRecord
    {
        $row = $this->dbal->fetchAssociative(
            "SELECT * FROM {$this->tableNamePrefix}_node WHERE nodeaggregateid = :id",
            ['id' => $nodeAggregateId],
        );
        return $row === false ? null : AuditNodeRecord::fromDatabaseRow($row);
    }

    /**
     * @param list<string> $nodeAggregateIds
     * @return array<string,AuditNodeRecord> indexed by node aggregate id
     */
    public function findNodesByIds(array $nodeAggregateIds): array
    {
        if ($nodeAggregateIds === []) {
            return [];
        }
        $rows = $this->dbal->fetchAllAssociative(
            "SELECT * FROM {$this->tableNamePrefix}_node WHERE nodeaggregateid IN (:ids)",
            ['ids' => $nodeAggregateIds],
            ['ids' => ArrayParameterType::STRING],
        );
        $nodes = [];
        foreach ($rows as $row) {
            $node = AuditNodeRecord::fromDatabaseRow($row);
            $nodes[$node->nodeAggregateId] = $node;
        }
        return $nodes;
    }

    /**
     * Case-insensitive substring search on the last known node title (includes removed nodes)
     *
     * @return list<AuditNodeRecord>
     */
    public function searchNodesByTitle(string $term, int $limit = 50): array
    {
        $rows = $this->dbal->createQueryBuilder()
            ->select('*')
            ->from($this->tableNamePrefix . '_node')
            ->where('LOWER(title) LIKE :term')
            ->setParameter('term', '%' . addcslashes(mb_strtolower($term), '%_\\') . '%')
            ->orderBy('removed', 'ASC')
            ->addOrderBy('title', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(AuditNodeRecord::fromDatabaseRow(...), $rows);
    }

    /**
     * Site nodes are the nodes that are their own site
     *
     * @return list<AuditNodeRecord>
     */
    public function findSiteNodes(): array
    {
        $rows = $this->dbal->fetchAllAssociative(
            "SELECT * FROM {$this->tableNamePrefix}_node WHERE sitenodeaggregateid = nodeaggregateid ORDER BY removed, nodename"
        );
        return array_map(AuditNodeRecord::fromDatabaseRow(...), $rows);
    }

    /**
     * The given nodes plus all their descendants, without descending into nodes of the given types (e.g. sub-pages).
     * Removed descendants are included.
     *
     * @param list<string> $nodeAggregateIds
     * @param list<string> $stopAtNodeTypeNames
     * @return list<string>
     */
    public function findDescendantIdsWithin(array $nodeAggregateIds, array $stopAtNodeTypeNames): array
    {
        if ($nodeAggregateIds === []) {
            return [];
        }
        $nodeTable = $this->tableNamePrefix . '_node';
        // an empty IN () list is invalid SQL, so use a value that never matches
        $stopAtNodeTypeNames = $stopAtNodeTypeNames !== [] ? $stopAtNodeTypeNames : [''];
        $ids = $this->dbal->fetchFirstColumn(
            <<<SQL
                WITH RECURSIVE subtree (nodeaggregateid) AS (
                    SELECT nodeaggregateid FROM {$nodeTable} WHERE nodeaggregateid IN (:ids)
                    UNION
                    SELECT n.nodeaggregateid FROM {$nodeTable} n
                    JOIN subtree s ON n.parentnodeaggregateid = s.nodeaggregateid
                    WHERE n.nodetypename NOT IN (:stopAtNodeTypeNames)
                )
                SELECT nodeaggregateid FROM subtree
            SQL,
            ['ids' => $nodeAggregateIds, 'stopAtNodeTypeNames' => $stopAtNodeTypeNames],
            ['ids' => ArrayParameterType::STRING, 'stopAtNodeTypeNames' => ArrayParameterType::STRING],
        );
        return array_values(array_filter($ids, is_string(...)));
    }

    /**
     * Sequence number of the newest event in the event store (independent of any filter); null if there are no events
     */
    public function findLatestSequenceNumber(): ?int
    {
        $sequenceNumber = $this->dbal->fetchOne("SELECT MAX(sequencenumber) FROM {$this->eventTableName}");
        return is_numeric($sequenceNumber) ? (int)$sequenceNumber : null;
    }

    /**
     * All event types that occurred
     *
     * @return list<string>
     */
    public function findEventTypes(): array
    {
        $eventTypes = $this->dbal->fetchFirstColumn(
            "SELECT DISTINCT eventtype FROM {$this->tableNamePrefix}_event ORDER BY eventtype"
        );
        return array_values(array_filter($eventTypes, is_string(...)));
    }

    /**
     * All workspace names that ever occurred (including removed workspaces)
     *
     * @return list<string>
     */
    public function findWorkspaceNames(): array
    {
        $workspaceNames = $this->dbal->fetchFirstColumn(
            "SELECT DISTINCT workspacename FROM {$this->tableNamePrefix}_contentstream ORDER BY workspacename"
        );
        return array_values(array_filter($workspaceNames, is_string(...)));
    }
}
