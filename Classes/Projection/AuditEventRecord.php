<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Projection;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;

/**
 * One raw event from the event store, enriched with the resolved index fields
 */
#[Flow\Proxy(false)]
final readonly class AuditEventRecord
{
    public function __construct(
        public int $sequenceNumber,
        public \DateTimeImmutable $recordedAt,
        public string $eventType,
        public string $eventId,
        public string $streamName,
        public ?string $workspaceName,
        public ?string $contentStreamId,
        public ?string $userId,
        public ?string $nodeAggregateId,
        public ?string $siteNodeAggregateId,
        public string $payload,
        public ?string $metadata,
        public ?string $correlationId,
        public ?string $causationId,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            is_numeric($row['sequencenumber'] ?? null) ? (int)$row['sequencenumber'] : 0,
            new \DateTimeImmutable(self::string($row, 'recordedat'), new \DateTimeZone('UTC')),
            self::string($row, 'eventtype'),
            self::string($row, 'id'),
            self::string($row, 'stream'),
            self::nullableString($row, 'workspacename'),
            self::nullableString($row, 'contentstreamid'),
            self::nullableString($row, 'userid'),
            self::nullableString($row, 'nodeaggregateid'),
            self::nullableString($row, 'sitenodeaggregateid'),
            self::string($row, 'payload'),
            self::nullableString($row, 'metadata'),
            self::nullableString($row, 'correlationid'),
            self::nullableString($row, 'causationid'),
        );
    }

    /**
     * Payload as pretty-printed JSON (falls back to the raw string if it isn't valid JSON)
     */
    public function getPrettyPayload(): string
    {
        return self::prettyPrintJson($this->payload);
    }

    public function getPrettyMetadata(): ?string
    {
        return $this->metadata === null ? null : self::prettyPrintJson($this->metadata);
    }

    /**
     * Assets (images, documents, ...) referenced in the property values of the event payload,
     * e.g. of NodeAggregateWithNodeWasCreated or NodePropertiesWereSet
     *
     * @return list<array{propertyName: string, assetId: string, objectType: string}>
     */
    public function getReferencedAssets(): array
    {
        try {
            $payload = json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($payload)) {
            return [];
        }
        $assets = [];
        foreach (['initialPropertyValues', 'propertyValues'] as $propertyValuesKey) {
            $propertyValues = $payload[$propertyValuesKey] ?? null;
            if (!is_array($propertyValues)) {
                continue;
            }
            foreach ($propertyValues as $propertyName => $serializedPropertyValue) {
                $value = is_array($serializedPropertyValue) ? ($serializedPropertyValue['value'] ?? null) : null;
                if (!is_array($value)) {
                    continue;
                }
                // single asset, or a list of assets (e.g. array<Neos\Media\Domain\Model\Asset>)
                foreach (array_is_list($value) ? $value : [$value] as $objectReference) {
                    $objectType = is_array($objectReference) ? ($objectReference['__flow_object_type'] ?? null) : null;
                    $identifier = is_array($objectReference) ? ($objectReference['__identifier'] ?? null) : null;
                    if (is_string($objectType) && is_string($identifier) && is_a($objectType, AssetInterface::class, true)) {
                        $assets[] = [
                            'propertyName' => (string)$propertyName,
                            'assetId' => $identifier,
                            'objectType' => $objectType,
                        ];
                    }
                }
            }
        }
        return $assets;
    }

    private static function prettyPrintJson(string $json): string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function string(array $row, string $key): string
    {
        return is_scalar($row[$key] ?? null) ? (string)$row[$key] : '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function nullableString(array $row, string $key): ?string
    {
        return is_scalar($row[$key] ?? null) ? (string)$row[$key] : null;
    }
}
