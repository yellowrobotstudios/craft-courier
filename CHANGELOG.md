# Changelog

## 1.0.0 - 2026-06-04

Initial release. Courier is event-driven transactional notifications for Craft CMS, created and edited entirely in the control panel — including on production.

### Added
- **Triggers** as Craft elements — define the event, conditions, recipients, channels, and message in the CP; editable on production like entries.
- **Event registry** with a curated picker: entries, users (created, updated, deleted, activated, email-verified, added to / removed from group, suspended, unsuspended), assets, categories, and Commerce (order completed/paid/status-changed, subscription created/canceled). Raw "Other…" events cover custom events, and modules/plugins can register their own via `RegisterEventTriggersEvent`.
- **Visual condition builder** (native Craft `ElementCondition`) with element-type-specific rules (Section/Type/Author for entries; Group/Email/Username/Admin/etc. for users; order rules for Commerce). Build **OR-combined condition groups** — any group matching fires the trigger, while rules within a group are AND-combined. A built-in **Twig condition** rule covers logic the builder can't express.
- **Channels**: Craft Email, SMTP, Slack, Discord, Webhook, and SMS — multi-select per trigger, with env-var credentials.
- Per-trigger **send mode** — one message to the whole list, or an individual message per recipient (recipients never see each other).
- **Preview** that renders subject + body and resolves **To/Cc/Bcc** against a sample element, with the sample picker scoped to the trigger's conditions; plus **Send test** (email redirects to you; other channels fire their live destination).
- **Template body files** — `templates/_courier/{handle}.twig` overrides the CP body, shown as "managed in file".
- **Date-based triggers** — fire off a date instead of an event — each element's own date field, or one shared date for all matching elements: *"3 days before `renewalDate`"* or *"7 days before June 14, to every registrant."* "Before" means "N days or less" (late-entering elements still send while their date is ahead); sends are re-checked against current state at send time and skipped if stale; one send per element per date. Dispatched by `php craft courier/scheduler/run` (one crontab line, mutex-locked).
- **Permissions**: `courier:manage` (full wiring) and `courier:edit-templates` (message content only).
- Per-send **logging** with a CP viewer — filterable by Sent/Failed/Tests — recording the channel, originating trigger, and whether the send was a test. Unseen failures surface as a badge on the CP nav until you've looked.
