# Courier for Craft CMS

Event-driven notifications for Craft CMS 5 — built and managed entirely in the control panel. Pick an event, write a message, choose where it goes. No deploy required to add or change a notification, and it sends across email, Slack, Discord, SMS, and webhooks.

## Why Courier

Most notification setups in Craft are defined in PHP config files, which means a developer and a deploy every time the team wants a new email. Courier makes notifications **content, not code** — a site admin can wire up "email the author when an entry is published" from the CP, on production, without touching a file.

- **CP-managed triggers** — define the whole flow (event → condition → recipients → channels → message) in the control panel.
- **Multi-channel** — Craft Email, SMTP, Slack, Discord, Webhook, and SMS, each a self-contained driver.
- **Real conditions** — Craft's native condition builder with OR-combined groups, plus a built-in Twig condition rule for logic the builder can't express.
- **Preview & test** — render a notification against a real element and send a test to any single channel before going live.
- **Extensible** — register your own events and channel types from a module or plugin with no core changes.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

From the Plugin Store, or with Composer:

```bash
composer require yellowrobot/craft-courier
php craft plugin/install courier
```

## Concepts

**Trigger** — the unit of configuration (a Craft element). It binds an *event* to a *message*, its *recipients*, and one or more *channels*, with optional conditions. Triggers are editable on production, like entries.

**Channel** — *how* a notification is delivered (the transport). A channel is a configured instance of a channel *type*; credentials and self-addressed URLs live on the channel, while recipients live on the trigger. A trigger just picks which channels fire.

**Event** — *when* a notification fires, chosen from a curated registry (e.g. `entry.created`, `user.activated`) that maps friendly keys to the underlying Craft events.

## Date-based triggers

Triggers can also fire **on a date** instead of an event, in three shapes:

- **A date on each element** — one send per element as its own date approaches:
  *“3 days before each order’s `renewalDate`, at 9:00am.”* Renewal reminders, expiry
  warnings, re-engagement nudges.
- **A specific date, sent once** — a single send to the recipients list, no element
  involved: *“Remind the team about the maintenance window on June 20.”*
- **A specific date, one send per matching element** — the date is shared, the
  audience comes from the conditions: *“7 days before the ceremony, email every
  completed registration order.”*

- **“Before” means “N days or less.”** An element that enters the window late (a booking
  made the day before its event, with a 3-days-before reminder) still sends — immediately —
  as long as its date is ahead. Past dates never fire.
- **Once per element per date.** Resaves don’t refire; if the date *moves*, the stale
  send quietly cancels itself and the new date gets its own.
- **Re-checked at send time.** Conditions are evaluated again at the send moment against
  current state — if the element no longer matches (or was deleted, or the trigger was
  disabled), the send is skipped and logged as such.

Date triggers are dispatched by the scheduler. Add one crontab line:

```bash
* * * * * php craft courier/scheduler/run
```

It’s mutex-locked and a fast no-op when nothing is due. Sites that only use event
triggers don’t need it.

## Channels

| Type | Sends via | Notes |
|------|-----------|-------|
| **Craft Email** | Craft's mailer | Uses your Craft email settings — zero config (pure transport) |
| **SMTP** | Its own SMTP transport | Bring your own host/credentials (SendGrid, Mailgun, Postmark, Gmail…) |
| **Slack** | Incoming webhook | One webhook = one channel |
| **Discord** | Incoming webhook | One webhook = one channel |
| **Webhook** | JSON `POST`/`PUT` | For Zapier, Make, n8n, or your own API |
| **SMS** | Twilio or AWS SNS | Provider credentials via env vars |

Recipient fields (on the trigger) and credential/URL fields (on the channel) support **environment variables** (e.g. `$SLACK_WEBHOOK_URL`). When you reference a secret by env var, only the reference is stored in the database — the value stays in your `.env`. Name secrets with a keyword like `TOKEN`/`SECRET`/`PASSWORD` and Craft masks the resolved value in the CP.

## Writing the message

Subject and body are **Twig**, rendered against the element that fired the event. Use `object` (works for any event) or its type alias:

```twig
Subject: New post: {{ entry.title }}
Body:    {{ entry.title }} was published by {{ entry.author.fullName }}.
```

`object` and the alias (`entry`, `user`, `asset`…) are the same record. The trigger screen shows the available variables for the selected event, and **Twig is validated when you save** — a syntax error is caught immediately instead of failing at send time.

## Recipients

Who a notification goes to lives on the **trigger**, not the channel — so one channel (transport) can serve many triggers, each addressing its own audience. The **To**, **Cc**, and **Bcc** fields are Twig + environment variables, resolved per event:

```twig
To: {{ object.author.email }}, $FALLBACK_NOTIFY
```

Comma-separate multiple addresses; an env var that itself holds a comma list is expanded. Self-addressed channels (Slack, Discord, Webhook) carry their own destination and ignore these fields; SMS reads **To** as phone numbers.

**Send mode** controls how a multi-recipient list is delivered:

- **List** (default) — one message addressed to the whole list.
- **Individual** — a separate message per *To* recipient, so recipients never see each other and the body can personalize per person. Cc/Bcc ride along on each message; self-addressed channels always send once.

### Template body files (optional)

By default the body lives in the database, edited in the CP. If you prefer your IDE and version control, drop a file at `templates/_courier/{handle}.twig` — it takes precedence and the CP field becomes read-only ("managed in file"). Delete the file and the DB body resumes.

## Preview & testing

On a trigger's **Preview** tab:

- Pick a real sample element so tokens resolve to actual values.
- **Render** to see the email exactly as it'll look (HTML + plain-text).
- **Send test** — email/SMTP goes only to *you*; Slack/Discord/webhook/SMS fire their real destination (the live post is the only faithful preview for those), targeting a single channel you choose.

Every send is recorded under **Courier → Logs**, which you can clear manually or auto-prune via a retention setting. Recent delivery failures surface as a badge on the Courier nav.

## Permissions

- `courier:manage` — full access (triggers, channels, conditions).
- `courier:edit-templates` — edit message copy only, for content editors.

## Extending Courier

### Register a custom event

Any Craft (or your own) event can become a trigger via `RegisterEventTriggersEvent`:

```php
use yii\base\Event;
use craft\elements\User;
use craft\web\User as WebUser;
use yellowrobot\courier\services\EventRegistry;
use yellowrobot\courier\events\RegisterEventTriggersEvent;

Event::on(
    EventRegistry::class,
    EventRegistry::EVENT_REGISTER_EVENT_TRIGGERS,
    function (RegisterEventTriggersEvent $event) {
        $event->triggers['user.loggedIn'] = [
            'label' => 'Logged in',
            'category' => 'Users',
            'class' => WebUser::class,
            'event' => WebUser::EVENT_AFTER_LOGIN,
            'elementType' => User::class,
            // pull the bound object off the event when it isn't $event->sender
            'objectExtractor' => fn($e) => $e->identity,
        ];
    }
);
```

### Register a custom channel

Implement a channel type and register it via Craft's `RegisterComponentTypesEvent`:

```php
use yii\base\Event;
use craft\events\RegisterComponentTypesEvent;
use yellowrobot\courier\services\Channels;

Event::on(
    Channels::class,
    Channels::EVENT_REGISTER_CHANNEL_TYPES,
    fn(RegisterComponentTypesEvent $e) => $e->types[] = \modules\MyChannelType::class,
);
```

A channel type extends `yellowrobot\courier\channels\BaseChannelType` (a `craft\base\Component`) and implements the static `handle()` and `displayName()`, plus `getSettingsHtml()`, `validateSettings()`, and `send()`.

## Settings

| Setting | Description |
|---------|-------------|
| `defaultLayout` | Optional Twig layout that wraps every rendered email body |
| `logRetentionDays` | Prune logs older than N days during garbage collection (`null`/`0` = keep forever) |

## License

Commercial — available through the Craft Plugin Store.

---

Made by [Yellow Robot Studios](https://yellowrobotstudios.com).
