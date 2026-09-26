<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Projection;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Factory\SubscriberFactoryDependencies;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;

/**
 * Options:
 * - eventTableName: name of the event store table, defaults to "cr_<contentRepositoryId>_events"
 *   (the naming of {@see \Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory})
 *
 * @implements ProjectionFactoryInterface<AuditIndexProjection>
 */
final class AuditIndexProjectionFactory implements ProjectionFactoryInterface
{
    public function __construct(
        private readonly Connection $dbal,
    ) {
    }

    public function build(
        SubscriberFactoryDependencies $projectionFactoryDependencies,
        array $options,
    ): AuditIndexProjection {
        $contentRepositoryId = $projectionFactoryDependencies->contentRepositoryId->value;
        $eventTableName = $options['eventTableName'] ?? null;
        return new AuditIndexProjection(
            $this->dbal,
            sprintf('cr_%s_p_sandstorm_auditview', $contentRepositoryId),
            is_string($eventTableName) ? $eventTableName : sprintf('cr_%s_events', $contentRepositoryId),
        );
    }
}
