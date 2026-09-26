<?php

declare(strict_types=1);

namespace Sandstorm\NeosAuditView\Controller;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Sandstorm\NeosAuditView\Projection\AuditEventQuery;
use Sandstorm\NeosAuditView\Projection\AuditEventRecord;
use Sandstorm\NeosAuditView\Projection\AuditIndexFinder;
use Sandstorm\NeosAuditView\Projection\AuditNodeRecord;
use Sandstorm\NeosAuditView\Projection\EventTypeGroup;
use Sandstorm\NeosAuditView\Presentation\EventTypeLabels;

/**
 * Backend module listing the raw content repository events, newest first
 */
#[Flow\Scope('singleton')]
class AuditTrailController extends AbstractModuleController
{
    private const PAGE_SIZE = 50;
    private const MAX_MATCHED_NODES = 50;
    /** number of events shown newer and older than the source event when navigating to an event */
    private const WINDOW_SIZE = 25;
    private const WINDOW_STEP = 10;
    private const MAX_WINDOW_SIZE = 500;

    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject]
    protected EventTypeLabels $eventTypeLabels;

    /**
     * Backend module to link referenced assets to; must support the "searchTerm=id:<asset id>" URL parameter
     * like the Flowpack.Media.Ui module. null = no links.
     */
    #[Flow\InjectConfiguration(path: 'mediaModulePath')]
    protected ?string $mediaModulePath = null;

    protected function initializeIndexAction(): void
    {
        // lists (eventTypes[]=...) have numeric keys, which the property mapper only maps when explicitly allowed
        foreach (['eventTypes', 'eventTypeGroups', 'dimensions'] as $argumentName) {
            $this->arguments->getArgument($argumentName)->getPropertyMappingConfiguration()->allowAllProperties();
        }
    }

    /**
     * @param string|null $site site node aggregate id
     * @param string|null $workspace workspace name
     * @param string|null $user Neos user id
     * @param string|null $node node aggregate id or (part of) a node title
     * @param string|null $includeContent "0" to only show events of the matched nodes themselves; by default,
     *                                    events of their content (descendants except sub-pages) are included
     * @param array<string> $eventTypes event types, e.g. ["NodeAggregateWasRemoved"]
     * @param array<string> $eventTypeGroups {@see EventTypeGroup} values; selects all (occurring) event types of the group
     * @param array<string> $dimensions dimension values as "<dimension id>:<value>", e.g. ["language:en"]
     * @param int|null $before only show events older than this sequence number
     * @param string|null $goTo navigate to the event with this sequence number (or the closest older matching one)
     * @param string|null $goToTime navigate to the newest event before this local time ("2026-09-26T14:00")
     * @param string|null $timezoneOffset timezone offset of $goToTime in minutes as in JS (UTC - local); UTC if missing
     * @param int|null $newer number of events shown newer than the source event when navigating
     * @param int|null $older number of events shown older than the source event when navigating
     */
    public function indexAction(
        ?string $site = null,
        ?string $workspace = null,
        ?string $user = null,
        ?string $node = null,
        ?string $includeContent = null,
        array $eventTypes = [],
        array $eventTypeGroups = [],
        array $dimensions = [],
        ?int $before = null,
        ?string $goTo = null,
        ?string $goToTime = null,
        ?string $timezoneOffset = null,
        ?int $newer = null,
        ?int $older = null,
    ): void {
        $site = self::emptyToNull($site);
        $workspace = self::emptyToNull($workspace);
        $user = self::emptyToNull($user);
        $node = self::emptyToNull($node);
        // an option can stand for several event types with the same label, e.g. "A,B,C"
        $selectedEventTypes = array_values(array_unique(array_filter(
            array_merge(...array_map(fn (string $value) => explode(',', $value), array_values($eventTypes))),
            fn (string $value) => $value !== ''
        )));
        $selectedEventTypeGroups = array_values(array_filter(array_map(
            EventTypeGroup::tryFrom(...),
            array_unique($eventTypeGroups)
        )));
        $isContentIncluded = $includeContent !== '0';

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $finder = $contentRepository->projectionState(AuditIndexFinder::class);
        $documentNodeTypeNames = self::findDocumentNodeTypeNames($contentRepository);

        // event type filter: individually selected types + all occurring types of the selected groups
        $occurringEventTypes = $finder->findEventTypes();
        $effectiveEventTypes = $selectedEventTypes;
        foreach ($occurringEventTypes as $occurringEventType) {
            if (in_array(EventTypeGroup::fromEventType($occurringEventType), $selectedEventTypeGroups, true)) {
                $effectiveEventTypes[] = $occurringEventType;
            }
        }
        $effectiveEventTypes = array_values(array_unique($effectiveEventTypes));

        $selectedDimensionValues = array_values(array_unique(array_filter($dimensions, fn (string $value) => str_contains($value, ':'))));

        // node filter: exact aggregate id first, otherwise title search
        $matchedNodes = [];
        $nodeAggregateIds = null;
        if ($node !== null) {
            $exactNode = $finder->findNodeById($node);
            $matchedNodes = $exactNode !== null ? [$exactNode] : $finder->searchNodesByTitle($node, self::MAX_MATCHED_NODES);
            $nodeAggregateIds = array_map(fn (AuditNodeRecord $n) => $n->nodeAggregateId, $matchedNodes);
            if ($isContentIncluded) {
                $nodeAggregateIds = $finder->findDescendantIdsWithin($nodeAggregateIds, array_keys($documentNodeTypeNames));
            }
        }

        $filterQuery = new AuditEventQuery(
            siteNodeAggregateId: $site,
            workspaceName: $workspace,
            userId: $user,
            nodeAggregateIds: $nodeAggregateIds,
            eventTypes: $effectiveEventTypes !== [] ? $effectiveEventTypes : null,
            // technical events are hidden unless explicitly selected
            excludedEventTypes: array_values(array_filter(
                $occurringEventTypes,
                fn (string $eventType) => EventTypeGroup::fromEventType($eventType)->isHiddenByDefault()
            )),
            dimensionSpacePointHashes: $selectedDimensionValues !== []
                ? self::findMatchingDimensionSpacePointHashes($contentRepository, $selectedDimensionValues)
                : null,
            limit: self::PAGE_SIZE,
        );

        // navigation to an event (by sequence number or date & time) or the regular list, newest first
        $goToSequenceNumber = is_numeric($goTo) && (int)$goTo > 0 ? (int)$goTo : null;
        $goToTime = self::emptyToNull($goToTime);
        $goToDateTime = $goToTime !== null ? self::parseGoToTime($goToTime, $timezoneOffset) : null;
        $navigationNotice = $goToTime !== null && $goToDateTime === null && $goToSequenceNumber === null
            ? $this->translate('goTo.notice.invalidTime')
            : null;
        $navigation = null;
        $navigationArguments = [];
        $hasOlderEvents = false;
        if ($goToSequenceNumber !== null || $goToDateTime !== null) {
            $newer = self::clampWindowSize($newer);
            $older = self::clampWindowSize($older);
            $navigationArguments = $goToSequenceNumber !== null
                ? ['goTo' => (string)$goToSequenceNumber]
                : array_filter(['goToTime' => $goToTime, 'timezoneOffset' => self::emptyToNull($timezoneOffset)], fn (?string $value) => $value !== null);
            $navigation = self::navigate($finder, $filterQuery, $goToSequenceNumber, $goToDateTime, $newer, $older);
            $events = $navigation['events'];
            $navigationNotice = $this->buildNavigationNotice($navigation['source'], $navigation['isFallback'], $goToSequenceNumber, $goToTime);
        } else {
            $events = $finder->findEvents($filterQuery->withRange(self::PAGE_SIZE, beforeSequenceNumber: $before));
            $hasOlderEvents = count($events) > self::PAGE_SIZE;
            $events = array_slice($events, 0, self::PAGE_SIZE);
        }
        $lastEvent = end($events);
        $sourceSequenceNumber = $navigation !== null ? $navigation['source']?->sequenceNumber : null;

        $siteOptions = $this->buildSiteOptions($finder);
        $userOptions = $this->buildUserOptions();
        $eventNodes = $finder->findNodesByIds(array_values(array_unique(array_filter(
            array_map(fn (AuditEventRecord $e) => $e->nodeAggregateId, $events)
        ))));

        $filter = [
            'site' => $site,
            'workspace' => $workspace,
            'user' => $user,
            'node' => $node,
        ];
        $activeFilters = array_filter($filter, fn (?string $value) => $value !== null);
        if ($selectedEventTypes !== []) {
            $activeFilters['eventTypes'] = $selectedEventTypes;
        }
        if ($selectedEventTypeGroups !== []) {
            $activeFilters['eventTypeGroups'] = array_map(fn (EventTypeGroup $group) => $group->value, $selectedEventTypeGroups);
        }
        if ($selectedDimensionValues !== []) {
            $activeFilters['dimensions'] = $selectedDimensionValues;
        }
        $filterArguments = $isContentIncluded ? $activeFilters : [...$activeFilters, 'includeContent' => '0'];
        $mediaModuleUri = $this->buildMediaModuleUri();
        $contentModuleUris = $this->buildContentModuleUris($contentRepository, array_keys(array_filter(
            $eventNodes,
            fn (AuditNodeRecord $eventNode) => isset($documentNodeTypeNames[$eventNode->nodeTypeName])
        )));
        $this->view->assignMultiple([
            'filter' => $filter,
            'isContentIncluded' => $isContentIncluded,
            // for building paging links which keep the current filters
            'filterArguments' => $filterArguments,
            // hidden form fields: the go-to forms keep the filters, the filter form keeps the go-to position
            'filterHiddenFields' => self::buildHiddenFields($filterArguments),
            'navigationHiddenFields' => self::buildHiddenFields($navigationArguments),
            'goTo' => [
                'sequenceNumber' => $goToSequenceNumber,
                'time' => $goToSequenceNumber === null && $goToDateTime !== null ? $goToTime : null,
                'notice' => $navigationNotice,
            ],
            'navigation' => $navigation !== null ? [
                'sourceSequenceNumber' => $sourceSequenceNumber,
                // "load more" links jump to the top button row resp. the previously oldest event to keep the position
                'newerUri' => $navigation['hasNewer']
                    ? $this->buildIndexUri([...$filterArguments, ...$navigationArguments, 'newer' => $newer + self::WINDOW_STEP, 'older' => $older], 'sandstorm-auditview-newer')
                    : null,
                'olderUri' => $navigation['hasOlder'] && $lastEvent !== false
                    ? $this->buildIndexUri([...$filterArguments, ...$navigationArguments, 'newer' => $newer, 'older' => $older + self::WINDOW_STEP], 'sandstorm-auditview-event-' . $lastEvent->sequenceNumber)
                    : null,
                'backToNewestUri' => $this->buildIndexUri($filterArguments),
            ] : null,
            'isFiltered' => $activeFilters !== [],
            'isFirstPage' => $before === null || $navigation !== null,
            'siteOptions' => $siteOptions,
            'workspaceOptions' => $this->buildWorkspaceOptions($contentRepositoryId, $finder),
            'userOptions' => $userOptions,
            'eventTypeGroups' => $this->buildEventTypeGroups($occurringEventTypes, $selectedEventTypes, $selectedEventTypeGroups),
            'dimensionGroups' => self::buildDimensionGroups($contentRepository, $selectedDimensionValues),
            'matchedNodes' => $matchedNodes,
            'maxMatchedNodes' => self::MAX_MATCHED_NODES,
            'contentNodeCount' => $isContentIncluded && $nodeAggregateIds !== null ? count($nodeAggregateIds) - count($matchedNodes) : 0,
            'events' => array_map(function (AuditEventRecord $event) use ($userOptions, $siteOptions, $eventNodes, $documentNodeTypeNames, $mediaModuleUri, $contentModuleUris, $filterArguments, $sourceSequenceNumber) {
                $eventNode = $event->nodeAggregateId !== null ? ($eventNodes[$event->nodeAggregateId] ?? null) : null;
                $nodeKind = match (true) {
                    $eventNode === null => null,
                    isset($documentNodeTypeNames[$eventNode->nodeTypeName]) => 'document',
                    default => 'content',
                };
                return [
                    'record' => $event,
                    'isSource' => $event->sequenceNumber === $sourceSequenceNumber,
                    'goToUri' => $this->buildIndexUri([...$filterArguments, 'goTo' => (string)$event->sequenceNumber]),
                    'label' => $this->eventTypeLabels->forEvent($event->eventType, $event->payload, $nodeKind),
                    'userLabel' => $event->userId !== null ? ($userOptions[$event->userId] ?? $event->userId) : null,
                    'siteLabel' => $event->siteNodeAggregateId !== null ? ($siteOptions[$event->siteNodeAggregateId] ?? $event->siteNodeAggregateId) : null,
                    'nodeLabel' => $eventNode?->getTitleOrName(),
                    'nodeTypeName' => $eventNode?->nodeTypeName,
                    'nodeKind' => $nodeKind,
                    'contentModuleUri' => $eventNode !== null ? ($contentModuleUris[$eventNode->nodeAggregateId] ?? null) : null,
                    'assets' => array_map(fn (array $asset) => [
                        ...$asset,
                        'mediaModuleUri' => $mediaModuleUri !== null
                            ? $mediaModuleUri . (str_contains($mediaModuleUri, '?') ? '&' : '?') . 'searchTerm=' . rawurlencode('id:' . $asset['assetId'])
                            : null,
                    ], $event->getReferencedAssets()),
                ];
            }, $events),
            'nextBefore' => $hasOlderEvents && $lastEvent !== false ? $lastEvent->sequenceNumber : null,
            // context for the filtered list: newest event overall + range of the shown events
            'latestSequenceNumber' => $finder->findLatestSequenceNumber(),
            'shownSequenceNumbers' => $events !== [] && $lastEvent !== false
                ? ['newest' => $events[0]->sequenceNumber, 'oldest' => $lastEvent->sequenceNumber]
                : null,
        ]);
    }

    /**
     * Events around the source event: the event with the given sequence number resp. the newest event before the
     * given time (only events matching the filters). If there is no such event, the oldest matching event is used,
     * which then is the closest newer one ("fallback").
     *
     * @return array{events: list<AuditEventRecord>, source: ?AuditEventRecord, isFallback: bool, hasNewer: bool, hasOlder: bool}
     */
    private static function navigate(
        AuditIndexFinder $finder,
        AuditEventQuery $filterQuery,
        ?int $goToSequenceNumber,
        ?\DateTimeImmutable $goToDateTime,
        int $newer,
        int $older,
    ): array {
        $source = $finder->findEvents($goToSequenceNumber !== null
            ? $filterQuery->withRange(1, beforeSequenceNumber: $goToSequenceNumber + 1)
            : $filterQuery->withRange(1, recordedBefore: $goToDateTime))[0] ?? null;
        $isFallback = false;
        if ($source === null) {
            $source = $finder->findEvents($filterQuery->withRange(1, oldestFirst: true))[0] ?? null;
            $isFallback = $source !== null;
        }
        if ($source === null) {
            return ['events' => [], 'source' => null, 'isFallback' => false, 'hasNewer' => false, 'hasOlder' => false];
        }
        // the source event + older ones, newest first
        $olderEvents = $finder->findEvents($filterQuery->withRange($older + 1, beforeSequenceNumber: $source->sequenceNumber + 1));
        // newer ones, oldest first
        $newerEvents = $finder->findEvents($filterQuery->withRange($newer, afterSequenceNumber: $source->sequenceNumber, oldestFirst: true));
        return [
            'events' => [...array_reverse(array_slice($newerEvents, 0, $newer)), ...array_slice($olderEvents, 0, $older + 1)],
            'source' => $source,
            'isFallback' => $isFallback,
            'hasNewer' => count($newerEvents) > $newer,
            'hasOlder' => count($olderEvents) > $older + 1,
        ];
    }

    private function buildNavigationNotice(?AuditEventRecord $source, bool $isFallback, ?int $goToSequenceNumber, ?string $goToTime): ?string
    {
        if ($source === null) {
            return null;
        }
        if ($goToSequenceNumber !== null) {
            return match (true) {
                $source->sequenceNumber === $goToSequenceNumber => null,
                $isFallback => $this->translate('goTo.notice.closestNewer', [$goToSequenceNumber, $source->sequenceNumber]),
                default => $this->translate('goTo.notice.closestOlder', [$goToSequenceNumber, $source->sequenceNumber]),
            };
        }
        // the time as entered, in the user's local time
        $time = str_replace('T', ' ', (string)$goToTime);
        return $isFallback
            ? $this->translate('goTo.notice.noEventBeforeTime', [$time, $source->sequenceNumber])
            : $this->translate('goTo.notice.time', [$time, $source->sequenceNumber]);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function buildIndexUri(array $arguments, ?string $section = null): string
    {
        return $this->uriBuilder
            ->reset()
            ->setSection($section ?? '')
            ->uriFor('index', $arguments);
    }

    /**
     * @param array<string,string|list<string>> $arguments
     * @return list<array{name: string, value: string}>
     */
    private static function buildHiddenFields(array $arguments): array
    {
        $fields = [];
        foreach ($arguments as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $singleValue) {
                $fields[] = ['name' => 'moduleArguments[' . $name . ']' . (is_array($value) ? '[]' : ''), 'value' => $singleValue];
            }
        }
        return $fields;
    }

    /**
     * Parses the value of a datetime-local input (local time of the browser) to a point in time
     *
     * @param string|null $timezoneOffset minutes as returned by JS Date.getTimezoneOffset() (UTC - local)
     */
    private static function parseGoToTime(string $value, ?string $timezoneOffset): ?\DateTimeImmutable
    {
        foreach (['!Y-m-d\\TH:i', '!Y-m-d\\TH:i:s'] as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($dateTime === false || ($errors !== false && $errors['warning_count'] > 0)) {
                continue;
            }
            $offsetMinutes = is_numeric($timezoneOffset) ? max(-1440, min(1440, (int)$timezoneOffset)) : 0;
            $interval = new \DateInterval('PT' . abs($offsetMinutes) . 'M');
            return $offsetMinutes >= 0 ? $dateTime->add($interval) : $dateTime->sub($interval);
        }
        return null;
    }

    private static function clampWindowSize(?int $size): int
    {
        return $size === null ? self::WINDOW_SIZE : max(0, min(self::MAX_WINDOW_SIZE, $size));
    }

    /**
     * Links to the given documents in the content module (Neos UI), for documents which currently exist in the
     * user's workspace. The Neos UI opens the document in the user's personal workspace and fails for
     * documents it cannot resolve, so removed documents get no link.
     *
     * @param list<string> $documentNodeAggregateIds
     * @return array<string,string> node aggregate id => uri
     */
    private function buildContentModuleUris(ContentRepository $contentRepository, array $documentNodeAggregateIds): array
    {
        if ($documentNodeAggregateIds === []) {
            return [];
        }
        $workspaceName = WorkspaceName::forLive();
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser !== null) {
            try {
                $workspaceName = $this->workspaceService->getPersonalWorkspaceForUser($contentRepository->id, $currentUser->getId())->workspaceName;
            } catch (\RuntimeException) {
                // no personal workspace yet (will be created when opening the content module): live is the base
            }
        }
        $contentGraph = $contentRepository->getContentGraph($workspaceName);

        // module links must be built on the main request, not on the module sub request
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($this->request->getMainRequest());

        $uris = [];
        foreach ($documentNodeAggregateIds as $documentNodeAggregateId) {
            $nodeAggregateId = NodeAggregateId::fromString($documentNodeAggregateId);
            $nodeAggregate = $contentGraph->findNodeAggregateById($nodeAggregateId);
            if ($nodeAggregate === null) {
                continue;
            }
            foreach ($nodeAggregate->coveredDimensionSpacePoints as $dimensionSpacePoint) {
                if ($contentRepository->getContentSubgraph($workspaceName, $dimensionSpacePoint)->findNodeById($nodeAggregateId) === null) {
                    continue;
                }
                $nodeAddress = NodeAddress::create($contentRepository->id, $workspaceName, $dimensionSpacePoint, $nodeAggregateId);
                $uris[$documentNodeAggregateId] = $uriBuilder
                    ->reset()
                    ->uriFor('index', ['node' => $nodeAddress->toJson()], 'Backend', 'Neos.Neos.Ui');
                break;
            }
        }
        return $uris;
    }

    private function buildMediaModuleUri(): ?string
    {
        if ($this->mediaModulePath === null || $this->mediaModulePath === '') {
            return null;
        }
        // module links must be built on the main request, not on the module sub request
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($this->request->getMainRequest());
        return $uriBuilder
            ->reset()
            ->uriFor('index', ['module' => $this->mediaModulePath], 'Backend\\Module', 'Neos.Neos');
    }

    /**
     * View model for the event type filter (Fusion component Dropdown): occurring event types grouped by
     * {@see EventTypeGroup}, empty groups omitted
     *
     * @param list<string> $occurringEventTypes
     * @param list<string> $selectedEventTypes
     * @param list<EventTypeGroup> $selectedEventTypeGroups
     * @return list<array{key: string, label: string, isSelected: bool, options: list<array{value: string, label: string, isSelected: bool}>}>
     */
    private function buildEventTypeGroups(array $occurringEventTypes, array $selectedEventTypes, array $selectedEventTypeGroups): array
    {
        $groups = [];
        foreach (EventTypeGroup::cases() as $group) {
            $groupEventTypes = array_values(array_filter(
                $occurringEventTypes,
                fn (string $eventType) => EventTypeGroup::fromEventType($eventType) === $group
            ));
            if ($groupEventTypes === []) {
                continue;
            }
            $isGroupSelected = in_array($group, $selectedEventTypeGroups, true);
            // event types with the same label (e.g. the three kinds of language variants) become one option
            $eventTypesByLabel = [];
            foreach ($groupEventTypes as $eventType) {
                $eventTypesByLabel[$this->eventTypeLabels->forEventType($eventType)][] = $eventType;
            }
            // sorted by meaning (EventTypeGroup::DISPLAY_ORDER), unlisted event types alphabetically after them
            uksort($eventTypesByLabel, fn (string|int $labelA, string|int $labelB) => [
                min(array_map(EventTypeGroup::displayPosition(...), $eventTypesByLabel[$labelA])),
                mb_strtolower((string)$labelA),
            ] <=> [
                min(array_map(EventTypeGroup::displayPosition(...), $eventTypesByLabel[$labelB])),
                mb_strtolower((string)$labelB),
            ]);
            $options = [];
            foreach ($eventTypesByLabel as $label => $labelEventTypes) {
                $options[] = [
                    'value' => implode(',', $labelEventTypes),
                    'label' => (string)$label,
                    'isSelected' => $isGroupSelected || array_diff($labelEventTypes, $selectedEventTypes) === [],
                ];
            }
            $groups[] = [
                'key' => $group->value,
                'label' => $this->translate('eventTypeGroup.' . $group->value),
                'isSelected' => $isGroupSelected,
                'options' => $options,
            ];
        }
        return $groups;
    }

    /**
     * View model for the dimension filter: one group per content dimension, one option per dimension value
     *
     * @param list<string> $selectedDimensionValues "<dimension id>:<value>"
     * @return list<array{key: string, label: string, isSelected: bool, options: list<array{value: string, label: string, isSelected: bool}>}>
     */
    private static function buildDimensionGroups(ContentRepository $contentRepository, array $selectedDimensionValues): array
    {
        $groups = [];
        foreach ($contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority() as $dimension) {
            $dimensionLabel = $dimension->getConfigurationValue('label');
            $options = [];
            foreach ($dimension->values as $dimensionValue) {
                $optionValue = $dimension->id->value . ':' . $dimensionValue->value;
                $valueLabel = $dimensionValue->getConfigurationValue('label');
                $options[] = [
                    'value' => $optionValue,
                    'label' => is_string($valueLabel) ? $valueLabel : $dimensionValue->value,
                    'isSelected' => in_array($optionValue, $selectedDimensionValues, true),
                ];
            }
            $groups[] = [
                'key' => $dimension->id->value,
                'label' => is_string($dimensionLabel) ? $dimensionLabel : $dimension->id->value,
                'isSelected' => false,
                'options' => $options,
            ];
        }
        return $groups;
    }

    /**
     * Hashes of all dimension space points matching the selected dimension values:
     * OR within a dimension, AND across dimensions (dimensions without selection are unrestricted)
     *
     * @param list<string> $selectedDimensionValues "<dimension id>:<value>"
     * @return list<string>
     */
    private static function findMatchingDimensionSpacePointHashes(ContentRepository $contentRepository, array $selectedDimensionValues): array
    {
        $selectedValuesByDimension = [];
        foreach ($selectedDimensionValues as $selectedDimensionValue) {
            [$dimensionId, $value] = explode(':', $selectedDimensionValue, 2);
            $selectedValuesByDimension[$dimensionId][] = $value;
        }
        $hashes = [];
        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            foreach ($selectedValuesByDimension as $dimensionId => $values) {
                if (!in_array($dimensionSpacePoint->coordinates[$dimensionId] ?? null, $values, true)) {
                    continue 2;
                }
            }
            $hashes[] = $dimensionSpacePoint->hash;
        }
        return $hashes;
    }

    /**
     * @param array<int,mixed> $arguments
     */
    private function translate(string $id, array $arguments = []): string
    {
        return $this->translator->translateById($id, $arguments, null, null, 'Modules', 'Sandstorm.NeosAuditView') ?? $id;
    }

    /**
     * @return array<string,true> names of all node types which are documents (pages), as keys
     */
    private static function findDocumentNodeTypeNames(ContentRepository $contentRepository): array
    {
        $documentNodeTypeNames = [];
        foreach ($contentRepository->getNodeTypeManager()->getNodeTypes() as $nodeType) {
            if ($nodeType->isOfType(NodeTypeNameFactory::NAME_DOCUMENT)) {
                $documentNodeTypeNames[$nodeType->name->value] = true;
            }
        }
        return $documentNodeTypeNames;
    }

    /**
     * @return array<string,string> site node aggregate id => label
     */
    private function buildSiteOptions(AuditIndexFinder $finder): array
    {
        $options = [];
        foreach ($finder->findSiteNodes() as $siteNode) {
            $siteEntity = $siteNode->nodeName !== null ? $this->siteRepository->findOneByNodeName($siteNode->nodeName) : null;
            $label = $siteEntity !== null ? (string)$siteEntity->getName() : $siteNode->getLabel();
            $options[$siteNode->nodeAggregateId] = $siteNode->removed ? $label . ' (removed)' : $label;
        }
        asort($options);
        return $options;
    }

    /**
     * @return array<string,string> workspace name => label
     */
    private function buildWorkspaceOptions(ContentRepositoryId $contentRepositoryId, AuditIndexFinder $finder): array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $options = [];
        foreach ($contentRepository->findWorkspaces() as $workspace) {
            $title = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName)->title->value;
            $options[$workspace->workspaceName->value] = $title !== $workspace->workspaceName->value
                ? sprintf('%s (%s)', $title, $workspace->workspaceName->value)
                : $title;
        }
        foreach ($finder->findWorkspaceNames() as $workspaceName) {
            $options[$workspaceName] ??= $workspaceName . ' (removed)';
        }
        uksort($options, fn (string $a, string $b) => match (true) {
            $a === WorkspaceName::WORKSPACE_NAME_LIVE => -1,
            $b === WorkspaceName::WORKSPACE_NAME_LIVE => 1,
            default => strnatcasecmp($options[$a], $options[$b]),
        });
        return $options;
    }

    /**
     * @return array<string,string> user id => label
     */
    private function buildUserOptions(): array
    {
        $options = [];
        foreach ($this->userService->getUsers() as $user) {
            assert($user instanceof User);
            $options[$user->getId()->value] = sprintf('%s (%s)', $user->getLabel(), $this->userService->getUsername($user) ?? '-');
        }
        asort($options);
        return $options;
    }

    private static function emptyToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;
        return $value === '' ? null : $value;
    }
}
