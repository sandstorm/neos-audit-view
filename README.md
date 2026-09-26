# Sandstorm.NeosAuditView

Neos 9 backend module (**Management → Audit trail**) to browse the content repository event log, newest first.

It answers questions like: Which nodes were deleted recently, and by whom? Who changed a specific page last?
What changed in a specific language, workspace or site? Which image was used by a removed image element?

For now, events are shown mostly raw (payload and metadata as pretty-printed JSON); a friendlier presentation for
non-technical editors is planned.

## Features

### Event list

- 50 events per page, newest first, with "Older events" / "Newest events" paging.
- Header per event: sequence number, timestamp (in the browser's timezone; UTC without JavaScript), editor-friendly
  action (e.g. "Page deleted", "Content edited", "Changes published"), affected node type and node title / node name.
- Details: user, workspace, site, node, referenced assets, technical event type, stream; payload and metadata
  collapsed by default.
- Events of content nodes are indented; events of pages and events without a node (publish, discard, …) are not.
- **Show in content module** – for events of pages which currently exist in the user's workspace
  (opens the page in the Neos UI, in the user's personal workspace; removed pages get no link).
- **Show in media module** – for assets (images, documents) referenced in property values of an event.

### Filters

All filters are combined (AND). Every filter state is a URL, so it can be bookmarked or shared.
A filter is only shown if it offers more than one option (e.g. multiple sites or dimension values) – or if it
currently has a value, e.g. from a link.

| Filter | Behaviour |
|---|---|
| Site | events of nodes inside the site (also for nodes which were removed meanwhile) |
| Dimensions | multi-select of the content dimension values (e.g. languages); shows events affecting a matching dimension space point (OR within a dimension, AND across dimensions). Events without dimension information (workspace / content stream events) are hidden while this filter is active |
| Workspace | current workspaces plus removed ones |
| User | the Neos user who initiated the event (`initiatingUserId` metadata) |
| Node | the node aggregate ID, or a (partial) node title; the title search also finds removed nodes. Matched nodes are listed above the results |
| Include page content | on by default: for matched pages, events of the content below them are included as well (e.g. text changes), but not those of sub-pages |
| Event type | multi-select with editor-friendly names, grouped into *Content changes*, *Publishing & workspaces*, *Technical* and *Other*; selecting a group selects all its event types (including ones that occur later). Only event types that actually occurred are listed. *Technical* (content stream) events are hidden unless explicitly selected |

### Go to an event

Below the filters, you can jump to a point in the event log – within the filtered events:

- **Event number** – the event with this sequence number; if it doesn't match the filters, the closest older
  matching event (a notice tells so).
- **Date & time** – the newest matching event recorded *before* the picked time (browser's local time; the
  browser sends its timezone offset along, without JavaScript the time is interpreted as UTC).

The source event is highlighted (blue border) and scrolled into view, with 25 newer and 25 older events around it.
"Load 10 newer / older events" at the top and bottom extend the window. Changing filters keeps the position;
"Back to newest events" leaves it. The `#1234` in each event header links to the event with its surrounding events,
which is handy for sharing.

### Event type labels

Event types get editor-friendly labels (English and German, `Resources/Private/Translations/*/Modules.xlf`), even if
they are a little less technically precise. The most specific translation wins (`Classes/Presentation/EventTypeLabels.php`):

| Key | Example |
|---|---|
| `eventType.<type>.<variant>.<nodeKind>` | `eventType.SubtreeWasTagged.removed.document` → "Page deleted (restorable)" |
| `eventType.<type>.<variant>` | `eventType.WorkspaceWasPublished.partial` → "Changes published (partly)" |
| `eventType.<type>.<nodeKind>` | `eventType.NodeAggregateWasRemoved.content` → "Content deleted (purged)" |
| `eventType.<type>` | `eventType.NodeAggregateWasRemoved` → "Deleted (purged)" (also used in the filter) |

`nodeKind` is `document` (node type inherits from `Neos.Neos:Document`) or `content`; `variant` is the subtree tag
(`disabled` = hidden, `removed` = soft deleted) or `partial` for partial publishing. Unknown event types are shown
with their raw name. The module UI itself is translated to English and German as well.

In the event type filter, event types are sorted by meaning (created, deleted, edited, … / published, discarded, …),
defined in `EventTypeGroup::DISPLAY_ORDER`; unlisted event types follow alphabetically.

## Installation

Requires Neos 9 and MySQL / MariaDB. Developed and tested with Neos 9.2.

```bash
composer config repositories.neos-audit-view vcs https://github.com/sandstorm/neos-audit-view
composer require sandstorm/neos-audit-view
```

Restart the application (Flow 9 recomputes the package order at startup), then set up and fill the audit index projection:

```bash
./flow cr:setup --content-repository default
./flow subscription:replay Sandstorm.NeosAuditView:AuditIndex --content-repository default
./flow cr:status --content-repository default   # the AuditIndex projection should be up to date
```

The replay indexes all existing events once; afterwards the projection is kept up to date automatically.

### Updating

After updating the package, run `./flow cr:setup` if `./flow cr:status` reports that a setup is required
(the index schema changed, e.g. the index on `recordedat` for "go to date & time"). If the index collects new data (e.g. new index tables), also replay the projection:
`./flow subscription:replay Sandstorm.NeosAuditView:AuditIndex`.

## Access

The module is available for

- `Neos.Neos:Administrator`
- `Sandstorm.NeosAuditView:AuditTrailViewer` – assign this role *in addition* to an editor role
  (it does not grant backend access on its own).

The module is located in the *Management* section, because the *Administration* section is restricted to administrators.

## Configuration

```yaml
Sandstorm:
  NeosAuditView:
    # Backend module to link assets referenced in event payloads to. It must support the
    # "searchTerm=id:<asset id>" URL parameter (Flowpack.Media.Ui does). null = no links.
    mediaModulePath: 'management/mediaui'
```

If your event store table does not follow the `cr_<id>_events` naming, configure it via the projection option `eventTableName`:

```yaml
Neos:
  ContentRepositoryRegistry:
    presets:
      'default':
        projections:
          'Sandstorm.NeosAuditView:AuditIndex':
            options:
              eventTableName: 'my_events_table'
```

## How it works

The event store can only be filtered by stream and event type, and events don't know which site they belong to.
The package therefore registers a content repository projection (`Sandstorm.NeosAuditView:AuditIndex`, for the
`default` content repository preset) which maintains four tables:

| Table | Content |
|---|---|
| `cr_<id>_p_sandstorm_auditview_event` | one row per event: sequence number, type, workspace, content stream, user, node, site |
| `cr_<id>_p_sandstorm_auditview_node` | node aggregate → parent, site, node type, node name, last known title (removed nodes are kept) |
| `cr_<id>_p_sandstorm_auditview_contentstream` | content stream → workspace, to resolve events that only carry a content stream ID |
| `cr_<id>_p_sandstorm_auditview_event_dsp` | event → dimension space points it affects |

The module (`AuditTrailController`, Fusion view) queries this index via `AuditIndexFinder` and joins the raw events
from `cr_<id>_events`.

Technical notes:

- The index uses MySQL/MariaDB specific SQL (`INSERT … ON DUPLICATE KEY UPDATE`, recursive CTEs).
- All string columns of the index are `utf8mb4`, so any user input can be compared against them.
- Workspace, content stream and node of an event are derived via the core marker interfaces `EmbedsWorkspaceName`,
  `EmbedsContentStreamId` and `EmbedsNodeAggregateId`; the dimension space points generically from the event's
  properties.
- Fusion templates use `I18n.translate()` – the `I18n.id()` translation token is mutable and caused overwritten labels
  when several translations were rendered together.

## Limitations

- Node titles and site assignment are tracked per node aggregate ("last write wins" across workspaces and dimensions).
- Published changes appear twice (in the user workspace and in `live`); use the workspace filter to narrow down.
- Content module links open the site of the current backend domain; with multiple sites on different domains,
  links to pages of another site may not work.
