# Plan: `sandstorm/neos-audit-view` – Neos backend module for browsing CR events

## Context
Neos 9 (here 9.2.0-beta3, CR id `default`, table `cr_default_events`) stores every change as an event, but there is no UI to inspect them. We want a reusable package (later published at github.com/sandstorm/neos-audit-view) with a backend module listing events newest-first, filterable by site, workspace, user and node (aggregate ID or title). v1 shows events as raw pretty-printed JSON; a friendlier presentation comes later. Access: new role `Sandstorm.NeosAuditView:AuditTrailViewer` + `Neos.Neos:Administrator`.

Decisions taken with the user:
- **Site filter via own audit index projection** (fast, includes removed nodes).
- **Node "name" = title/label search**, resolved to aggregate IDs.
- Develop in `app/DistributionPackages/Sandstorm.NeosAuditView`.

## Architecture
The raw event store API can only filter by stream/event type, and events do not carry the site. So we add a **CR projection** that writes a small, indexed lookup table per event, and the module queries that index joined with `cr_<id>_events` for the raw payload/metadata.

Template to follow: `Packages/Application/Neos.Neos/Classes/PendingChangesProjection/ChangeProjection.php` (+ `ChangeProjectionFactory.php`, `ChangeFinder` as projection state) — same DBAL setup/status/resetState/apply pattern.

### Package layout (`app/DistributionPackages/Sandstorm.NeosAuditView/`)
```
composer.json                      name sandstorm/neos-audit-view, type neos-package,
                                   require neos/neos ^9.0, neos/fusion-form; PSR-4 Sandstorm\NeosAuditView\
Configuration/
  Settings.yaml                    module registration + projection registration
  Policy.yaml                      role + privileges
  Views.yaml                       FusionView for the controller
Classes/
  Projection/AuditIndexProjection.php         implements ProjectionInterface<AuditIndexFinder>
  Projection/AuditIndexProjectionFactory.php  implements ProjectionFactoryInterface
  Projection/AuditIndexFinder.php             ProjectionStateInterface; query API
  Projection/AuditEventQuery.php              value object (filters + cursor + limit)
  Projection/AuditEventRecord.php             DTO: seq, recordedAt, type, stream, user, workspace, site, nodeAggregateId, payload, metadata
  Controller/AuditTrailController.php         extends AbstractModuleController, FusionView
Resources/Private/Fusion/Root.fusion + Components (Index page, FilterForm, EventList, EventItem)
Resources/Private/Translations/en/Modules.xlf
Resources/Public/Styles/AuditView.css         minimal (pre formatting, filter bar)
README.md
```
Root `app/composer.json`: add `"sandstorm/neos-audit-view": "*"` (path repo `./DistributionPackages/*` already exists).

### Projection tables (prefix `cr_<crId>_p_sandstorm_auditview_…`, MySQL/MariaDB)
1. **`_event`** – one row per event:
   `sequencenumber` (PK, = event store seq), `recordedat`, `eventtype`, `workspacename` (nullable), `contentstreamid` (nullable), `userid` (nullable, from metadata `initiatingUserId`), `nodeaggregateid` (nullable), `sitenodeaggregateid` (nullable).
   Indexes: `(workspacename, sequencenumber)`, `(userid, sequencenumber)`, `(nodeaggregateid, sequencenumber)`, `(sitenodeaggregateid, sequencenumber)`.
2. **`_node`** – node → site/title lookup (last write wins across workspaces/dimensions; good enough for audit search):
   `nodeaggregateid` (PK), `parentnodeaggregateid`, `sitenodeaggregateid`, `nodetypename`, `title` (nullable), `removed` flag. Index on `title` prefix, `parentnodeaggregateid`.
3. **`_contentstream`** – `contentstreamid` (PK) → `workspacename`, so content-stream-only events (ContentStreamWasForked/Closed/…) still get a workspace.

`apply(EventInterface $event, EventEnvelope $envelope)`:
- Always insert an `_event` row (every event type, including unknown ones), deriving fields via the core marker interfaces in `Neos\ContentRepository\Core\Feature\Common\`: `EmbedsWorkspaceName`, `EmbedsContentStreamId`, `EmbedsNodeAggregateId`. Special-case Workspace events (`WorkspaceWasPublished` → `sourceWorkspaceName`, `*WorkspaceWasCreated`, `WorkspaceWasDiscarded/Rebased/Renamed/Removed` → `workspaceName`); if workspace still missing, look up `_contentstream`. User from `$envelope->event->metadata` key `InitiatingEventMetadata::INITIATING_USER_ID`. Site from `_node` lookup of `nodeaggregateid`.
- Maintain `_node`:
  - `RootNodeAggregateWithNodeWasCreated` → row with no site.
  - `NodeAggregateWithNodeWasCreated` → site = parent's site; if parent is the `Neos.Neos:Sites` root, site = the node itself. Title from initial property values (`title` property) if present.
  - `NodePropertiesWereSet` containing `title` → update title.
  - `NodeAggregateWasMoved` to a new parent → update parent; if site changed, update subtree via recursive CTE over `parentnodeaggregateid`.
  - `NodeAggregateWasRemoved` → set `removed = 1` (keep for audit search).
- Maintain `_contentstream` from `RootWorkspaceWasCreated`, `WorkspaceWasCreated`, `WorkspaceWasDiscarded/Rebased/Published` (new content stream ids), `WorkspaceBaseWorkspaceWasChanged`, `WorkspaceWasRenamed`.
- Wrap per-event writes in the projection's DBAL connection like ChangeProjection does.

Registration (Settings.yaml):
```yaml
Neos:
  ContentRepositoryRegistry:
    presets:
      default:
        projections:
          'Sandstorm.NeosAuditView:AuditIndex':
            factoryObjectName: Sandstorm\NeosAuditView\Projection\AuditIndexProjectionFactory
```
Existing events are indexed once with `./flow cr:setup` + `./flow subscription:replay Sandstorm.NeosAuditView:AuditIndex` (documented in README).

### Query (`AuditIndexFinder::findEvents(AuditEventQuery)`)
Single DBAL query: `SELECT i.*, e.stream, e.payload, e.metadata, e.id, e.correlationid, e.causationid FROM <index>_event i JOIN cr_<id>_events e USING/ON sequencenumber WHERE … ORDER BY i.sequencenumber DESC LIMIT :limit+1` (the +1 row tells if there's an older page).
Filters (all optional, AND-combined): `sitenodeaggregateid = ?`, `workspacename = ?`, `userid = ?`, `nodeaggregateid IN (?)`, cursor `sequencenumber < :before`. Plus helper methods: `searchNodesByTitle(string $term, int $limit): list<{id,title,nodeType,removed}>` (LIKE on `_node.title`), `findDistinctWorkspaceNames()` (to also show removed workspaces).
Obtain the finder via `$contentRepository->projectionState(AuditIndexFinder::class)`.

### Controller (`AuditTrailController`, Fusion view like `Neos.Workspace.Ui` `WorkspaceController`)
`indexAction(?string $site, ?string $workspace, ?string $user, ?string $node, ?int $before)`:
- CR id from `SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId`.
- Dropdown data: sites via `SiteRepository::findAll()` → resolve site node aggregate id (`ContentGraph(live)->findRootNodeAggregateByType(NodeTypeNameFactory::forSites())` + `findChildNodeAggregateByName`); workspaces via `ContentRepository::findWorkspaces()` + `WorkspaceService::getWorkspaceMetadata()` titles, merged with `findDistinctWorkspaceNames()`; users via `UserService::getUsers()` (value = `User::getId()`).
- Node input: if the value matches a `_node` row by aggregate ID → filter by that ID; otherwise title search → IDs; show the matched nodes (title, type, removed) above the list so the user sees what was matched.
- Resolve user IDs in results to display names (`UserService::findUserById`, try/catch for system user IDs), cache per request.
- Each event: seq, recordedAt, event type, stream, user label, workspace, site, node id + `<pre>` of `json_encode(..., JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)` for payload and metadata (in a `<details open>`).
- Paging: 50 per page, "Older events →" link carrying current filters + `before=<last seq>`, and a "Newest" link.

Fusion: `Sandstorm.NeosAuditView.AuditTrailController.index` component using `Neos.Fusion.Form:Form` (GET) for the filter bar; `Views.yaml` with fusionPathPatterns like Neos.Workspace.Ui (`Neos.Neos`, `Neos.Fusion`, `Neos.Fusion.Form`, own package). Register with `mainStylesheet: 'Lite'`.

### Module + security
Settings.yaml (module under **management**: the `administration` parent module privilege is Administrator-only, which would lock out AuditTrailViewers):
```yaml
Neos:
  Neos:
    modules:
      management:
        submodules:
          auditTrail:
            label: 'Sandstorm.NeosAuditView:Modules:auditTrail.label'
            description: 'Sandstorm.NeosAuditView:Modules:auditTrail.description'
            controller: 'Sandstorm\NeosAuditView\Controller\AuditTrailController'
            icon: 'fas fa-history'
            mainStylesheet: 'Lite'
            privilegeTarget: 'Sandstorm.NeosAuditView:Backend.Module.Management.AuditTrail'
            additionalResources:
              styleSheets: ['resource://Sandstorm.NeosAuditView/Public/Styles/AuditView.css']
```
Policy.yaml (pattern from `Shel.NodeTypes.Analyzer/Configuration/Policy.yaml`):
- `MethodPrivilege` `Sandstorm.NeosAuditView:AuditTrailController` → `method(Sandstorm\NeosAuditView\Controller\AuditTrailController->(?!initialize).*Action())`
- `ModulePrivilege` `Sandstorm.NeosAuditView:Backend.Module.Management.AuditTrail` → `management/auditTrail`
- Role `Sandstorm.NeosAuditView:AuditTrailViewer` (label/description; no parentRoles — assigned *in addition* to an editor role, since backend login needs `Neos.Neos:AbstractEditor`), GRANT both.
- `Neos.Neos:Administrator`: GRANT both.

## Verification
1. `composer update sandstorm/neos-audit-view` in `app/`, then **restart the neos container** (Flow 9 recomputes package order at startup; cache flush alone isn't enough).
2. `./flow cr:setup --content-repository default` → tables created; `./flow subscription:replay Sandstorm.NeosAuditView:AuditIndex`; `./flow cr:status` shows the projection as active/up to date. Check row counts: `_event` count == `cr_default_events` count.
3. `./flow configuration:show --path Neos.Neos.modules.management.submodules.auditTrail` and `--path Neos.ContentRepositoryRegistry.presets.default.projections` to confirm registration.
4. Backend: as Administrator open Management → Audit trail: newest event first; edit a page in the UI → new event appears on top with payload JSON; test each filter (site, workspace incl. user workspace, user, node by aggregate ID, node by title substring, title of a removed node); page through with "Older events".
5. Create a test user with Editor + `Sandstorm.NeosAuditView:AuditTrailViewer` → module visible; plain Editor → module not listed and direct URL denied.
6. `phpstan` (app/phpstan.neon, level 9, already covers DistributionPackages) passes. Optional: a functional PHPUnit test for `AuditIndexProjection` site resolution (create/move/remove) following the existing `Sandstorm.ImageAltTextGenerator/Tests` setup.
