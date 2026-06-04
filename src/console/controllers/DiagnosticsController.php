<?php

namespace yellowrobot\courier\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\conditions\entries\SectionConditionRule;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\conditions\users\EmailConditionRule;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use yellowrobot\courier\conditions\CourierSendCondition;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\EmailTemplate;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\models\ChannelConfig;
use yellowrobot\courier\records\EmailLogRecord;
use yellowrobot\courier\records\ScheduledSendRecord;
use yii\console\ExitCode;

/**
 * Inspect Courier's runtime wiring (event registry, channels).
 *
 * Usage:
 *   php craft courier/diagnostics/events
 *   php craft courier/diagnostics/channels
 */
class DiagnosticsController extends Controller
{
    use \yellowrobot\courier\traits\ResolvesRecipientsTrait;

    /**
     * Development-only verification harness. Several actions create and delete
     * real elements (users, sections, templates) and exercise live transports,
     * so the whole controller is gated to `devMode` — it's inert in production.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            $this->stderr("Courier diagnostics are only available when devMode is enabled.\n");
            return false;
        }
        return true;
    }

    /** Resolve a recipient expression against a sample element, like the preview does. */
    public function actionRecipienttest(): int
    {
        $entry = \craft\elements\Entry::find()->one();
        if (!$entry) {
            $this->stderr("no entry found\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $vars = ['object' => $entry, 'entry' => $entry];
        foreach (['{{ entry.title }}', '{{ entry.author.email }}', '{{ object.author.email }}', 'static@example.com'] as $expr) {
            $out = $this->renderRecipientList($expr, $vars);
            $this->stdout(sprintf("  %-30s => %s\n", $expr, json_encode($out)));
        }
        return ExitCode::OK;
    }

    /** The email channel config (tests that exercise recipient resolution need one). */
    private function firstEmailChannel(): ?ChannelConfig
    {
        foreach (Courier::$plugin->channels->getAllConfigs() as $config) {
            if ($config->type === 'email') {
                return $config;
            }
        }
        return null;
    }

    /**
     * Verify log deletion via deleteLog (scoped, by id). The clearAll/clearTests
     * methods are global deleteAll wrappers and aren't exercised here so this
     * never wipes real logs.
     */
    public function actionLogtest(): int
    {
        $log = Courier::$plugin->log;
        $rand = strtolower(StringHelper::randomString(6));

        $log->logSend(null, "logtest_{$rand}_a", 'craftEmail', 'a@x.test', 'A', 'sent', null, false);
        $log->logSend(null, "logtest_{$rand}_b", 'craftEmail', 'b@x.test', 'B', 'sent', null, false);

        $find = fn() => EmailLogRecord::find()->where(['like', 'templateHandle', "logtest_{$rand}_"])->all();
        $rows = $find();
        $startCount = count($rows);

        $deleted = $log->deleteLog((int) $rows[0]->id);
        $afterDelete = count($find());

        // Clean up the remaining seeded rows by id.
        foreach ($find() as $r) {
            $log->deleteLog((int) $r->id);
        }
        $afterCleanup = count($find());

        $checks = [
            'seeded 2 rows' => $startCount === 2,
            'deleteLog returned true' => $deleted === true,
            'deleteLog removed exactly one' => $afterDelete === 1,
            'remaining removed by id' => $afterCleanup === 0,
        ];
        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }
        $this->stdout("  (clearAll/clearTests not run — they're global and would wipe real logs)\n");
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Verify Twig is validated at save: good Twig saves, broken Twig is blocked. */
    public function actionTwigvalidatetest(): int
    {
        $elements = Craft::$app->getElements();
        $rand = strtolower(StringHelper::randomString(6));

        $good = new Trigger();
        $good->title = "Twigok {$rand}";
        $good->eventTrigger = 'user.created';
        $good->subject = 'Hi {{ user.email }}';
        $good->enabled = false;
        $goodSaves = $elements->saveElement($good);

        $bad = new Trigger();
        $bad->title = "Twigbad {$rand}";
        $bad->eventTrigger = 'user.created';
        $bad->subject = 'Hi {{ user.email ';  // unclosed
        $bad->enabled = false;
        $badSaves = $elements->saveElement($bad);

        $checks = [
            'valid Twig saves' => $goodSaves === true,
            'broken Twig blocked' => $badSaves === false,
            'error reported on subject' => !empty($bad->getErrors('subject')),
        ];
        $ok = $this->report($checks);

        if ($good->id) {
            if ($t = $good->getTemplate()) {
                $elements->deleteElement($t, true);
            }
            $elements->deleteElement($good, true);
        }
        $this->stdout("cleaned up\n");
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Verify duplicating a trigger gives it its own template (1:1 preserved). */
    public function actionDuplicatetest(): int
    {
        $elements = Craft::$app->getElements();
        $rand = strtolower(StringHelper::randomString(6));

        $orig = new Trigger();
        $orig->title = "Dup {$rand}";
        $orig->eventTrigger = 'user.created';
        $orig->subject = "Subject {$rand}";
        $orig->htmlBody = "<p>Body {$rand}</p>";
        $orig->enabled = false;
        $elements->saveElement($orig);
        $origTemplateId = $orig->templateId;

        $dup = $elements->duplicateElement($orig);
        $dupReload = Trigger::find()->id($dup->id)->status(null)->one();

        $checks = [
            'duplicate created' => $dup->id && $dup->id !== $orig->id,
            'duplicate has its own template' => $dupReload->templateId && $dupReload->templateId !== $origTemplateId,
            'duplicate carried the subject' => $dupReload->subject === "Subject {$rand}",
            'original template intact' => EmailTemplate::find()->id($origTemplateId)->status(null)->one() !== null,
        ];
        $ok = $this->report($checks);

        foreach ([$dupReload, $orig] as $t) {
            if ($tpl = $t->getTemplate()) {
                $elements->deleteElement($tpl, true);
            }
            $elements->deleteElement($t, true);
        }
        $this->stdout("cleaned up\n");
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Verify log retention prune removes old rows and keeps recent ones. */
    public function actionPrunetest(): int
    {
        $rand = strtolower(StringHelper::randomString(6));

        $old = new EmailLogRecord();
        $old->templateHandle = "prune_{$rand}_old";
        $old->recipient = 'x@x.test';
        $old->subject = 'x';
        $old->status = 'sent';
        $old->dateSent = \craft\helpers\Db::prepareDateForDb((new \DateTime())->modify('-400 days'));
        $old->save();

        $recent = new EmailLogRecord();
        $recent->templateHandle = "prune_{$rand}_recent";
        $recent->recipient = 'x@x.test';
        $recent->subject = 'x';
        $recent->status = 'sent';
        $recent->dateSent = \craft\helpers\Db::prepareDateForDb(new \DateTime());
        $recent->save();

        // Prune older than 365 days — only touches rows >1yr old (none real here).
        Courier::$plugin->log->pruneOlderThan(365);

        $checks = [
            'old row pruned' => EmailLogRecord::find()->where(['templateHandle' => "prune_{$rand}_old"])->one() === null,
            'recent row kept' => EmailLogRecord::find()->where(['templateHandle' => "prune_{$rand}_recent"])->one() !== null,
        ];
        $ok = $this->report($checks);

        if ($r = EmailLogRecord::find()->where(['templateHandle' => "prune_{$rand}_recent"])->one()) {
            $r->delete();
        }
        $this->stdout("cleaned up\n");
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** @param array<string,bool> $checks */
    private function report(array $checks): bool
    {
        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }
        return $ok;
    }

    /** Show the selectable condition rules per element type (entry vs user vs …). */
    public function actionConditionrulestest(): int
    {
        $events = Courier::$plugin->events;
        $conditions = Craft::$app->getConditions();

        foreach (['entry.saved', 'user.created', 'asset.created'] as $key) {
            $elementType = $events->getElementType($key);
            $condition = new CourierSendCondition();
            if ($elementType) {
                $condition->elementType = $elementType;
            }

            $ref = new \ReflectionMethod($condition, 'selectableConditionRules');
            $ref->setAccessible(true);
            $rules = $ref->invoke($condition);

            $names = [];
            foreach ($rules as $r) {
                try {
                    $rule = $conditions->createConditionRule(is_string($r) ? ['class' => $r] : $r);
                    $names[] = $rule->getLabel();
                } catch (\Throwable $e) {
                    $names[] = (is_string($r) ? $r : 'rule') . ' (?)';
                }
            }
            sort($names);
            $this->stdout(sprintf("%s  [%s]\n  %s\n\n", $key, $elementType ?: 'none', implode(', ', $names)));
        }
        return ExitCode::OK;
    }

    /** List all registered event triggers, grouped by category. */
    public function actionEvents(): int
    {
        $plugin = Courier::$plugin;
        foreach ($plugin->events->getGroupedOptions() as $opt) {
            if (isset($opt['optgroup'])) {
                $this->stdout("\n[{$opt['optgroup']}]\n");
            } else {
                $type = $plugin->events->getElementType($opt['value']) ?: '—';
                $this->stdout(sprintf("  %-28s %-28s (%s)\n", $opt['value'], $opt['label'], $type));
            }
        }
        $this->stdout("\n");
        return ExitCode::OK;
    }

    /** List channel types and configured channels. */
    public function actionChannels(): int
    {
        $channels = Courier::$plugin->channels;

        $this->stdout("\nChannel types:\n");
        foreach ($channels->getAllTypes() as $handle => $type) {
            $this->stdout(sprintf("  %-10s %-10s subject=%d html=%d\n", $handle, $type->getName(), $type->hasSubject() ? 1 : 0, $type->supportsHtml() ? 1 : 0));
        }

        $this->stdout("\nConfigured channels:\n");
        foreach ($channels->getAllConfigs() as $config) {
            $this->stdout(sprintf("  %-16s type=%-8s enabled=%d\n", $config->name, $config->type, $config->enabled ? 1 : 0));
        }
        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * Create a throwaway Trigger, verify persistence + 1:1 template + condition,
     * then delete it. Headless verification of the Trigger data layer.
     */
    public function actionSelftest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $channel = $this->firstEmailChannel();

        $trigger = new Trigger();
        $trigger->title = 'Selftest trigger';
        $trigger->handle = 'selftest' . strtolower(StringHelper::randomString(6));
        $trigger->eventTrigger = 'entry.created';
        $trigger->recipients = '{{ entry.author.email }}';
        $trigger->channelIds = $channel ? [$channel->uid] : [];
        $trigger->enabled = true;

        if (!$elements->saveElement($trigger)) {
            $this->stderr('FAIL: could not save trigger: ' . json_encode($trigger->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("saved trigger #{$trigger->id} (handle {$trigger->handle})\n");

        // Reload fresh from the DB
        $reloaded = Trigger::find()->id($trigger->id)->status(null)->one();
        if (!$reloaded) {
            $this->stderr("FAIL: could not reload trigger\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $checks = [
            'eventTrigger persisted' => $reloaded->eventTrigger === 'entry.created',
            'recipients persisted' => $reloaded->recipients === '{{ entry.author.email }}',
            'channelIds round-trip' => $reloaded->getChannelUids() === ($channel ? [$channel->uid] : []),
            '1:1 template created' => $reloaded->templateId !== null && $reloaded->getTemplate() !== null,
            'condition builder scoped' => $reloaded->getConditionBuilder()->elementType === \craft\elements\Entry::class,
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }

        // Cleanup: delete the linked template, then the trigger
        $template = $reloaded->getTemplate();
        if ($template) {
            $elements->deleteElement($template, true);
        }
        $elements->deleteElement($reloaded, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * End-to-end fire test: enabled trigger on `user.created` → save a user →
     * run the queue → assert a log row landed. Cleans up after itself.
     */
    public function actionFiretest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $channel = $this->firstEmailChannel();
        if (!$channel) {
            $this->stderr("FAIL: no channel configured\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rand = strtolower(StringHelper::randomString(6));

        $trigger = new Trigger();
        $trigger->title = 'Firetest';
        $trigger->handle = "firetest{$rand}";
        $trigger->eventTrigger = 'user.created';
        $trigger->channelIds = [$channel->uid];
        // Recipients live on the trigger (Twig, per-event); the channel is transport
        $trigger->recipients = '{{ user.email }}';
        $trigger->enabled = true;
        if (!$elements->saveElement($trigger)) {
            $this->stderr('FAIL: save trigger: ' . json_encode($trigger->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Re-register so the just-created trigger's listener is active this run
        $plugin->hook->registerTriggerListeners();

        // Fire user.created
        $user = new User();
        $user->username = "firetest_{$rand}";
        $user->email = "firetest_{$rand}@example.test";
        if (!$elements->saveElement($user)) {
            $this->stderr('FAIL: save user: ' . json_encode($user->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("saved user #{$user->id}, ran trigger #{$trigger->id}\n");

        // Process the queued send job(s)
        Craft::$app->getQueue()->run();

        // Assert a log row landed for this trigger
        $log = EmailLogRecord::find()->where(['triggerUid' => $trigger->uid])->one();
        $expectedRecipient = "firetest_{$rand}@example.test";
        $ok = $log !== null && $log->recipient === $expectedRecipient;
        if ($log) {
            $this->stdout(sprintf(
                "  [%s] log row: channel=%s status=%s isTest=%d recipient=%s (trigger Twig resolved)\n",
                $ok ? 'PASS' : 'FAIL', $log->channel, $log->status, $log->isTest ? 1 : 0, $log->recipient,
            ));
            if ($log->status === 'failed') {
                $this->stdout("         (send failed — likely no mail transport in this env; pipeline still fired: {$log->errorMessage})\n");
            }
        } else {
            $this->stderr("  [FAIL] no log row recorded for trigger\n");
        }

        // Cleanup
        if ($log) {
            $log->delete();
        }
        $elements->deleteElement($user, true);
        $template = $trigger->getTemplate();
        if ($template) {
            $elements->deleteElement($template, true);
        }
        $elements->deleteElement($trigger, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify env-var recipients resolve end-to-end through the send job: a
     * trigger whose `recipients` is an env var holding a comma list should
     * expand to every address (Twig first, then env resolution + re-split).
     */
    public function actionEnvtest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $channel = $this->firstEmailChannel();
        if (!$channel) {
            $this->stderr("FAIL: no channel configured\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rand = strtolower(StringHelper::randomString(6));
        putenv("COURIER_ENV_TEST=envone_{$rand}@example.test,envtwo_{$rand}@example.test");

        $trigger = new Trigger();
        $trigger->title = 'Envtest';
        $trigger->handle = "envtest{$rand}";
        $trigger->eventTrigger = 'user.created';
        $trigger->channelIds = [$channel->uid];
        // Recipients = a single env var that holds a comma list, set on the trigger
        $trigger->recipients = '$COURIER_ENV_TEST';
        $trigger->enabled = true;
        if (!$elements->saveElement($trigger)) {
            $this->stderr('FAIL: save trigger: ' . json_encode($trigger->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin->hook->registerTriggerListeners();

        $user = new User();
        $user->username = "envtest_{$rand}";
        $user->email = "envtest_{$rand}@example.test";
        if (!$elements->saveElement($user)) {
            $this->stderr('FAIL: save user: ' . json_encode($user->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        Craft::$app->getQueue()->run();

        $log = EmailLogRecord::find()->where(['triggerUid' => $trigger->uid])->one();
        $recipient = $log->recipient ?? '';
        $checks = [
            'env var resolved (not literal $VAR)' => !str_contains($recipient, '$COURIER_ENV_TEST'),
            'first list address present' => str_contains($recipient, "envone_{$rand}@example.test"),
            'second list address present (env list expanded)' => str_contains($recipient, "envtwo_{$rand}@example.test"),
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }
        $this->stdout("  logged recipient: {$recipient}\n");

        // Cleanup
        putenv('COURIER_ENV_TEST');
        if ($log) {
            $log->delete();
        }
        $elements->deleteElement($user, true);
        if ($tpl = $trigger->getTemplate()) {
            $elements->deleteElement($tpl, true);
        }
        $elements->deleteElement($trigger, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify user.addedToGroup / user.removedFromGroup fire precisely:
     *   1. Adding to a group fires addedToGroup once (gated by newGroupIds), not
     *      removedFromGroup.
     *   2. Re-assigning the same groups (a no-op) fires nothing — core early-returns.
     *   3. Removing from the group fires removedFromGroup, not addedToGroup.
     */
    public function actionGrouptest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $users = Craft::$app->getUsers();
        $groups = Craft::$app->getUserGroups();
        $channel = $this->firstEmailChannel();
        if (!$channel) {
            $this->stderr("FAIL: no channel configured\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rand = strtolower(StringHelper::randomString(6));

        // Temp group to watch
        $group = new \craft\models\UserGroup();
        $group->name = "Grouptest {$rand}";
        $group->handle = "grouptest{$rand}";
        if (!$groups->saveGroup($group)) {
            $this->stderr('SKIP: could not create a user group (Pro edition required?): ' . json_encode($group->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // User first, before listeners are active, so any default-group assignment
        // at creation isn't counted.
        $user = new User();
        $user->username = "grouptest_{$rand}";
        $user->email = "grouptest_{$rand}@example.test";
        if (!$elements->saveElement($user)) {
            $this->stderr('FAIL: save user: ' . json_encode($user->getErrors()) . "\n");
            $groups->deleteGroupById($group->id);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Trigger A: added. The split add/remove events are gated by the registry
        // (newGroupIds vs removedGroupIds); this test only touches the one group.
        $added = new Trigger();
        $added->title = 'Grouptest added';
        $added->handle = "grouptestadd{$rand}";
        $added->eventTrigger = 'user.addedToGroup';
        $added->channelIds = [$channel->uid];
        $added->recipients = '{{ user.email }}';
        $added->enabled = true;

        // Trigger B: removed.
        $removed = new Trigger();
        $removed->title = 'Grouptest removed';
        $removed->handle = "grouptestrem{$rand}";
        $removed->eventTrigger = 'user.removedFromGroup';
        $removed->channelIds = [$channel->uid];
        $removed->recipients = '{{ user.email }}';
        $removed->enabled = true;

        foreach ([$added, $removed] as $t) {
            if (!$elements->saveElement($t)) {
                $this->stderr('FAIL: save trigger: ' . json_encode($t->getErrors()) . "\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $plugin->hook->registerTriggerListeners();

        $countFor = fn(Trigger $t): int => (int) EmailLogRecord::find()->where(['triggerUid' => $t->uid])->count();

        // Step 1 — add to the group
        $users->assignUserToGroups($user->id, [$group->id]);
        Craft::$app->getQueue()->run();
        $addAfter1 = $countFor($added);
        $remAfter1 = $countFor($removed);

        // Step 2 — re-assign the same group (no-op)
        $users->assignUserToGroups($user->id, [$group->id]);
        Craft::$app->getQueue()->run();
        $addAfter2 = $countFor($added);

        // Step 3 — remove from the group
        $users->assignUserToGroups($user->id, []);
        Craft::$app->getQueue()->run();
        $addAfter3 = $countFor($added);
        $remAfter3 = $countFor($removed);

        $checks = [
            'add fires addedToGroup once' => $addAfter1 === 1,
            'add does not fire removedFromGroup' => $remAfter1 === 0,
            'no-op re-assign fires nothing' => $addAfter2 === 1,
            'remove fires removedFromGroup' => $remAfter3 === 1,
            'remove does not re-fire addedToGroup' => $addAfter3 === 1,
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }
        $this->stdout(sprintf("  counts: added=%d(noop→%d, remove→%d) removed=%d\n", $addAfter1, $addAfter2, $addAfter3, $remAfter3));

        // Cleanup
        EmailLogRecord::deleteAll(['triggerUid' => [$added->uid, $removed->uid]]);
        $elements->deleteElement($user, true);
        foreach ([$added, $removed] as $t) {
            if ($tpl = $t->getTemplate()) {
                $elements->deleteElement($tpl, true);
            }
            $elements->deleteElement($t, true);
        }
        $groups->deleteGroupById($group->id);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify the Preview sample picker scopes to the trigger's condition. Creates
     * two throwaway sections (A, B) + entries, builds a section-A condition, then
     * round-trips it through getConfig() → createCondition() → modifyQuery() — the
     * exact path PreviewField serializes and Craft's element index re-applies for
     * the modal's `condition` setting — and asserts only section-A entries match.
     */
    public function actionPreviewscopetest(): int
    {
        $entries = Craft::$app->getEntries();
        $elements = Craft::$app->getElements();
        $rand = strtolower(StringHelper::randomString(6));
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $sections = [];
        $entryTypes = [];

        $makeSection = function (string $key) use ($entries, $rand, $siteId, &$sections, &$entryTypes): ?Section {
            $et = new EntryType();
            $et->name = "Scope {$key} {$rand}";
            $et->handle = "scope{$key}{$rand}";
            if (!$entries->saveEntryType($et)) {
                $this->stderr("FAIL: entry type {$key}: " . json_encode($et->getErrors()) . "\n");
                return null;
            }
            $entryTypes[] = $et;

            $section = new Section();
            $section->name = "Scope {$key} {$rand}";
            $section->handle = "scope{$key}{$rand}";
            $section->type = Section::TYPE_CHANNEL;
            $section->setSiteSettings([new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => false])]);
            $section->setEntryTypes([$et]);
            if (!$entries->saveSection($section)) {
                $this->stderr("FAIL: section {$key}: " . json_encode($section->getErrors()) . "\n");
                return null;
            }
            $sections[] = $section;
            return $section;
        };

        $cleanup = function () use ($entries, $sections, $entryTypes): void {
            foreach ($sections as $s) {
                $entries->deleteSection($s);
            }
            foreach ($entryTypes as $et) {
                $entries->deleteEntryType($et);
            }
        };

        $a = $makeSection('a');
        $b = $makeSection('b');
        if (!$a || !$b) {
            $cleanup();
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $mkEntry = function (Section $s, string $title) use ($elements, $siteId): ?Entry {
            $e = new Entry();
            $e->sectionId = $s->id;
            $e->typeId = $s->getEntryTypes()[0]->id;
            $e->siteId = $siteId;
            $e->title = $title;
            return $elements->saveElement($e) ? $e : null;
        };
        $a1 = $mkEntry($a, "A1 {$rand}");
        $a2 = $mkEntry($a, "A2 {$rand}");
        $b1 = $mkEntry($b, "B1 {$rand}");
        if (!$a1 || !$a2 || !$b1) {
            $this->stderr("FAIL: could not create entries\n");
            $cleanup();
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Build the trigger's condition scoped to section A, as the CP would.
        $condition = new CourierSendCondition();
        $condition->elementType = Entry::class;
        $rule = new SectionConditionRule();
        $rule->setValues([$a->uid]);
        $condition->addConditionRule($rule);

        // Round-trip exactly like the live path: PreviewField serializes via
        // getConfig(); the element index re-instantiates and applies modifyQuery().
        $config = $condition->getConfig();
        $isEmpty = $condition->isEmpty();
        $applied = Craft::$app->getConditions()->createCondition($config);
        $query = Entry::find()->siteId($siteId);
        $applied->modifyQuery($query);
        $ids = $query->ids();

        $checks = [
            'condition not empty (so PreviewField attaches it)' => !$isEmpty,
            'config round-trips to an ElementCondition' => $applied instanceof \craft\elements\conditions\ElementCondition,
            'section A entry #1 in picker' => in_array($a1->id, $ids),
            'section A entry #2 in picker' => in_array($a2->id, $ids),
            'section B entry excluded from picker' => !in_array($b1->id, $ids),
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }
        $this->stdout(sprintf("  scoped query returned %d entries (expected 2)\n", count($ids)));

        // Cleanup
        foreach ([$a1, $a2, $b1] as $e) {
            $elements->deleteElement($e, true);
        }
        $cleanup();
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify the SMTP channel type delivers through its own transport. Targets
     * ddev's mailpit SMTP (127.0.0.1:1025, no auth) so it's a real end-to-end
     * send, not a mock.
     */
    public function actionSmtptest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $rand = strtolower(StringHelper::randomString(6));

        $smtp = new ChannelConfig();
        $smtp->name = "SMTP test {$rand}";
        $smtp->handle = "smtptest{$rand}";
        $smtp->type = 'smtp';
        $smtp->enabled = true;
        $smtp->settings = [
            'host' => '127.0.0.1',
            'port' => '1025',
            'encryption' => 'none',
            'fromEmail' => 'courier@example.test',
            'fromName' => 'Courier',
            'recipients' => '{{ user.email }}',
        ];
        if (!$plugin->channels->saveConfig($smtp)) {
            $this->stderr('FAIL: save smtp channel: ' . json_encode($smtp->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $trigger = new Trigger();
        $trigger->title = 'Smtptest';
        $trigger->handle = "smtptest{$rand}";
        $trigger->eventTrigger = 'user.created';
        $trigger->channelIds = [$smtp->uid];
        $trigger->enabled = true;
        if (!$elements->saveElement($trigger)) {
            $this->stderr('FAIL: save trigger: ' . json_encode($trigger->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin->hook->registerTriggerListeners();

        $user = new User();
        $user->username = "smtptest_{$rand}";
        $user->email = "smtptest_{$rand}@example.test";
        if (!$elements->saveElement($user)) {
            $this->stderr('FAIL: save user: ' . json_encode($user->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        Craft::$app->getQueue()->run();

        $log = EmailLogRecord::find()->where(['triggerUid' => $trigger->uid])->one();
        $checks = [
            'log row recorded' => $log !== null,
            'sent via smtp channel' => $log !== null && $log->channel === "smtptest{$rand}",
            'status sent (delivered to mailpit SMTP)' => $log !== null && $log->status === 'sent',
            'recipient resolved' => $log !== null && $log->recipient === "smtptest_{$rand}@example.test",
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }
        if ($log && $log->status === 'failed') {
            $this->stdout("         (error: {$log->errorMessage})\n");
        }

        // Cleanup
        if ($log) {
            $log->delete();
        }
        $elements->deleteElement($user, true);
        if ($tpl = $trigger->getTemplate()) {
            $elements->deleteElement($tpl, true);
        }
        $elements->deleteElement($trigger, true);
        $plugin->channels->deleteConfig($smtp);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify the trigger preview + send-test logic: rendering against a real
     * picked element, the empty-mock fallback, and the test-send override that
     * delivers to a chosen address and logs with isTest=1.
     */
    public function actionPreviewtest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $rand = strtolower(StringHelper::randomString(6));

        // A real element to preview against
        $user = new User();
        $user->username = "prev_{$rand}";
        $user->email = "prev_{$rand}@example.test";
        if (!$elements->saveElement($user)) {
            $this->stderr('FAIL: save user: ' . json_encode($user->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $makeTemplate = function () {
            $t = new EmailTemplate();
            $t->subject = 'Hi {{ user.email }}';
            $t->htmlBody = '<p>Hello {{ user.email }}</p>';
            $t->textBody = null;
            return $t;
        };

        // 1) Real element → variables resolve (mirrors resolvePreviewVariables)
        $alias = strtolower((new \ReflectionClass($user))->getShortName());
        $varsReal = ['object' => $user, $alias => $user];
        $renderedReal = $plugin->email->render('previewtest', $makeTemplate(), $varsReal);

        // 2) Empty mock (no element picked) → must render without throwing
        $mock = new User();
        $varsMock = ['object' => $mock, 'user' => $mock];
        $mockThrew = false;
        try {
            $renderedMock = $plugin->email->render('previewtest', $makeTemplate(), $varsMock);
        } catch (\Throwable $e) {
            $mockThrew = true;
            $renderedMock = ['subject' => '', 'html' => '', 'text' => ''];
        }

        // 3) Send-test override path: deliver to a chosen address, log isTest=1
        $channel = $this->firstEmailChannel();
        $testEmail = "tester_{$rand}@example.test";
        $sendResult = ['success' => false, 'error' => 'no channel'];
        if ($channel) {
            $rendered = $plugin->email->render('previewtest', $makeTemplate(), $varsReal);
            $sendResult = $plugin->channels->send($channel, [
                'to' => $testEmail,
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'text' => $rendered['text'],
            ]);
            $plugin->log->logSend(
                'preview-test-uid',
                'previewtest',
                $channel->handle,
                $testEmail,
                $rendered['subject'],
                $sendResult['success'] ? 'sent' : 'failed',
                $sendResult['error'] ?? null,
                true,
            );
        }
        $testLog = EmailLogRecord::find()->where(['triggerUid' => 'preview-test-uid'])->one();

        $checks = [
            'real element resolves subject Twig' => str_contains($renderedReal['subject'], $user->email),
            'real element resolves body Twig' => str_contains($renderedReal['html'], $user->email),
            'empty mock renders without throwing' => $mockThrew === false,
            'empty mock leaves token blank (no leak)' => !str_contains($renderedMock['html'], '{{'),
            'test send logged isTest=1' => $testLog !== null && (bool) $testLog->isTest === true,
            'test send recipient = chosen address' => $testLog !== null && $testLog->recipient === $testEmail,
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }
        if ($testLog && $testLog->status === 'failed') {
            $this->stdout("         (send failed — likely no mail transport in this env; override/log path still verified: {$testLog->errorMessage})\n");
        }

        // Cleanup
        if ($testLog) {
            $testLog->delete();
        }
        $elements->deleteElement($user, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify template body-file override precedence: a conventional Twig file
     * wins over the DB body; a template without a file falls back to the DB body.
     */
    public function actionOverridetest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $rand = strtolower(StringHelper::randomString(6));
        $dir = Craft::$app->getPath()->getSiteTemplatesPath() . '/_courier';
        @mkdir($dir, 0777, true);
        $file = "{$dir}/ovr-{$rand}.twig";
        file_put_contents($file, '<p>FILE-BODY-MARKER</p>');

        // Template WITH a conventional file override
        $withFile = new EmailTemplate();
        $withFile->title = 'Override test';
        $withFile->handle = "ovr-{$rand}";
        $withFile->subject = 'Subj';
        $withFile->htmlBody = '<p>DB-BODY-SHOULD-NOT-APPEAR</p>';
        $withFile->enabled = true;
        $elements->saveElement($withFile);

        // Template WITHOUT a file
        $noFile = new EmailTemplate();
        $noFile->title = 'No file';
        $noFile->handle = "nofile-{$rand}";
        $noFile->subject = 'Subj';
        $noFile->htmlBody = '<p>DB-ONLY-MARKER</p>';
        $noFile->enabled = true;
        $elements->saveElement($noFile);

        $withReload = EmailTemplate::find()->id($withFile->id)->status(null)->one();
        $noReload = EmailTemplate::find()->id($noFile->id)->status(null)->one();

        $renderedFile = $plugin->email->render($withReload->handle, $withReload, []);
        $renderedDb = $plugin->email->render($noReload->handle, $noReload, []);

        $checks = [
            'file override used' => str_contains($renderedFile['html'], 'FILE-BODY-MARKER'),
            'DB body suppressed when file present' => !str_contains($renderedFile['html'], 'DB-BODY-SHOULD-NOT-APPEAR'),
            'isBodyFileManaged true with file' => $withReload->isBodyFileManaged() === true,
            'DB body used when no file' => str_contains($renderedDb['html'], 'DB-ONLY-MARKER'),
            'isBodyFileManaged false without file' => $noReload->isBodyFileManaged() === false,
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }

        @unlink($file);
        $elements->deleteElement($withFile, true);
        $elements->deleteElement($noFile, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify deleting a trigger cascades to its 1:1 template (no orphans).
     */
    public function actionCascadetest(): int
    {
        $elements = Craft::$app->getElements();

        $trigger = new Trigger();
        $trigger->title = 'Cascade test';
        $trigger->eventTrigger = 'entry.created';
        $trigger->enabled = false;
        $elements->saveElement($trigger);
        $templateId = $trigger->templateId;

        $before = EmailTemplate::find()->id($templateId)->status(null)->one();
        $elements->deleteElement($trigger);                 // delete ONLY the trigger
        $after = EmailTemplate::find()->id($templateId)->status(null)->one();

        $checks = [
            'template created with trigger' => $before !== null,
            'template removed when trigger deleted (no orphan)' => $after === null,
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }

        // Hard-clean the trashed pair
        if ($tt = Trigger::find()->id($trigger->id)->trashed(true)->status(null)->one()) {
            $elements->deleteElement($tt, true);
        }
        if ($tpl = EmailTemplate::find()->id($templateId)->trashed(true)->status(null)->one()) {
            $elements->deleteElement($tpl, true);
        }
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Remove templates referenced by no trigger and matching no config hook.
     * (Cleans up leftovers from before the cascade-delete fix.)
     */
    public function actionCleanorphans(): int
    {
        // Destructive: deletes template elements. Require explicit confirmation
        // on top of the devMode gate.
        if (!$this->confirm('Delete every template element not linked to a trigger?')) {
            $this->stdout("Aborted.\n");
            return ExitCode::OK;
        }

        $linkedTemplateIds = array_filter(array_map(
            fn(Trigger $t) => $t->templateId,
            Trigger::find()->status(null)->all(),
        ));

        $deleted = 0;
        foreach (EmailTemplate::find()->status(null)->all() as $template) {
            $linked = in_array($template->id, $linkedTemplateIds, true);
            if (!$linked) {
                Craft::$app->getElements()->deleteElement($template);
                $this->stdout("  deleted orphan: {$template->title} ({$template->handle})\n");
                $deleted++;
            }
        }
        $this->stdout("removed {$deleted} orphan template(s)\n");
        return ExitCode::OK;
    }

    /**
     * Reproduce the web create path exactly (no handle set → derived) and report
     * whether the courier_triggers row actually lands.
     */
    public function actionCreaterepro(): int
    {
        $t = new Trigger();
        $t->title = 'New trigger';
        $t->eventTrigger = array_key_first(Courier::$plugin->events->getAll()) ?? 'entry.saved';
        $t->enabled = false;

        $saved = Craft::$app->getElements()->saveElement($t);
        $this->stdout('saveElement: ' . ($saved ? 'true' : 'false') . "\n");
        $this->stdout("element id: {$t->id}  handle: " . var_export($t->handle, true) . "\n");
        if (!$saved) {
            $this->stdout('ERRORS: ' . json_encode($t->getErrors()) . "\n");
        }

        $row = (new \craft\db\Query())->from('{{%courier_triggers}}')->where(['id' => $t->id])->one();
        $this->stdout("courier_triggers row for {$t->id}: " . ($row ? 'EXISTS' : 'MISSING') . "\n");
        if ($row) {
            $this->stdout('  stored handle: ' . var_export($row['handle'], true) . "  templateId: " . var_export($row['templateId'], true) . "\n");
        }

        if ($t->id) {
            if ($tpl = $t->getTemplate()) {
                Craft::$app->getElements()->deleteElement($tpl, true);
            }
            Craft::$app->getElements()->deleteElement($t, true);
            $this->stdout("(cleaned up)\n");
        }

        return ExitCode::OK;
    }

    /**
     * Verify the inline subject/body on the trigger writes through to the linked
     * template and reloads back onto the trigger (via the query join).
     */
    public function actionBodytest(): int
    {
        $elements = Craft::$app->getElements();

        $t = new Trigger();
        $t->title = 'Body test';
        $t->eventTrigger = 'entry.created';
        $t->subject = 'Hi {{ entry.title }}';
        $t->htmlBody = '<p>BODY-CONTENT-MARKER</p>';
        $t->enabled = false;
        $elements->saveElement($t);

        $tpl = $t->getTemplate();
        $reloaded = Trigger::find()->id($t->id)->status(null)->one();

        $checks = [
            'subject written to template' => $tpl && $tpl->subject === 'Hi {{ entry.title }}',
            'htmlBody written to template' => $tpl && str_contains((string) $tpl->htmlBody, 'BODY-CONTENT-MARKER'),
            'subject reloads onto trigger' => $reloaded && $reloaded->subject === 'Hi {{ entry.title }}',
            'htmlBody reloads onto trigger' => $reloaded && str_contains((string) $reloaded->htmlBody, 'BODY-CONTENT-MARKER'),
        ];

        // Update the body and confirm it propagates
        $reloaded->subject = 'Updated subject';
        $elements->saveElement($reloaded);
        $tpl2 = $reloaded->getTemplate();
        $checks['update propagates to template'] = $tpl2 && $tpl2->subject === 'Updated subject';

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }

        if ($tplFinal = $reloaded->getTemplate()) {
            $elements->deleteElement($tplFinal, true);
        }
        $elements->deleteElement($reloaded, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Verify trigger handles derive from the title (with collision suffixes).
     */
    public function actionHandletest(): int
    {
        $elements = Craft::$app->getElements();
        $title = 'My Cool Trigger ' . strtolower(StringHelper::randomString(4));
        $expected = StringHelper::toCamelCase($title);

        $a = new Trigger();
        $a->title = $title;
        $a->eventTrigger = 'entry.created';
        $a->enabled = false;
        $elements->saveElement($a);

        // Same title again → expect a "-2" suffix
        $b = new Trigger();
        $b->title = $title;
        $b->eventTrigger = 'entry.created';
        $b->enabled = false;
        $elements->saveElement($b);

        $checks = [
            "handle derived from title ({$expected})" => $a->handle === $expected,
            "collision suffixed ({$expected}2)" => $b->handle === "{$expected}2",
        ];

        $ok = true;
        foreach ($checks as $label => $pass) {
            $this->stdout(sprintf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label));
            $ok = $ok && $pass;
        }

        foreach ([$a, $b] as $t) {
            if ($tpl = $t->getTemplate()) {
                $elements->deleteElement($tpl, true);
            }
            $elements->deleteElement($t, true);
        }
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Print the "The date" select options for an element type — what the
     * date-field-options endpoint serves the trigger edit screen.
     */
    public function actionDatefields(string $elementType = Entry::class): int
    {
        if (!is_subclass_of($elementType, \craft\base\ElementInterface::class)) {
            $this->stderr("not an element type: {$elementType}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach (Courier::$plugin->scheduler->getDateFieldOptions($elementType) as $option) {
            if (isset($option['optgroup'])) {
                $this->stdout("{$option['optgroup']}:\n");
            } else {
                $this->stdout(sprintf("  %-24s %s\n", $option['value'], $option['label']));
            }
        }

        return ExitCode::OK;
    }

    /**
     * End-to-end date-trigger test: a scratch user + a date trigger keyed to
     * dateCreated (offset 0, send time = now) → scan schedules it → promote
     * re-checks and queues → queue sends → log row lands. Cleans up after.
     */
    public function actionDatetest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $channel = $this->firstEmailChannel();
        if (!$channel) {
            $this->stderr("FAIL: no channel configured\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rand = strtolower(StringHelper::randomString(6));

        // The date target: a user whose dateCreated is right now
        $user = new User();
        $user->username = "datetest_{$rand}";
        $user->email = "datetest_{$rand}@example.test";
        if (!$elements->saveElement($user)) {
            $this->stderr('FAIL: save user: ' . json_encode($user->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $trigger = new Trigger();
        $trigger->title = 'Datetest';
        $trigger->handle = "datetest{$rand}";
        $trigger->triggerMode = 'date';
        $trigger->dateElementType = User::class;
        $trigger->dateField = 'dateCreated';
        $trigger->dateOffsetDays = 0;
        $trigger->dateSendTime = (new \DateTime('now', $tz))->format('H:i');
        $trigger->channelIds = [$channel->uid];
        $trigger->recipients = '{{ user.email }}';
        $trigger->enabled = true;
        if (!$elements->saveElement($trigger)) {
            $this->stderr('FAIL: save trigger: ' . json_encode($trigger->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $inserted = $plugin->scheduler->scan();
        $row = \yellowrobot\courier\records\ScheduledSendRecord::find()
            ->where(['triggerId' => $trigger->id, 'elementId' => $user->id])
            ->one();
        $this->stdout(sprintf("  [%s] scan scheduled the send (inserted={$inserted})\n", $row ? 'PASS' : 'FAIL'));

        $result = $plugin->scheduler->promote();
        $this->stdout(sprintf(
            "  [%s] promote re-checked and queued (sent={$result['sent']} skipped={$result['skipped']})\n",
            $result['sent'] >= 1 ? 'PASS' : 'FAIL',
        ));

        Craft::$app->getQueue()->run();

        $log = EmailLogRecord::find()->where(['triggerUid' => $trigger->uid])->one();
        $ok = $row !== null && $result['sent'] >= 1 && $log !== null && $log->recipient === "datetest_{$rand}@example.test";
        if ($log) {
            $this->stdout(sprintf(
                "  [%s] log row: channel=%s status=%s recipient=%s\n",
                $ok ? 'PASS' : 'FAIL', $log->channel, $log->status, $log->recipient,
            ));
        } else {
            $this->stderr("  [FAIL] no log row recorded\n");
        }

        // Re-scan must NOT reschedule (the processed row is the marker)
        $plugin->scheduler->scan();
        $again = ScheduledSendRecord::find()
            ->where(['triggerId' => $trigger->id, 'elementId' => $user->id, 'processedAt' => null])
            ->exists();
        $this->stdout(sprintf("  [%s] re-scan didn't reschedule (once per element per date)\n", $again ? 'FAIL' : 'PASS'));
        $ok = $ok && !$again;

        // Cleanup
        if ($log) {
            $log->delete();
        }
        $elements->deleteElement($user, true);
        if ($tpl = $trigger->getTemplate()) {
            $elements->deleteElement($tpl, true);
        }
        $elements->deleteElement($trigger, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * E2E for once-audience fixed-date triggers: fixed date = today, audience
     * "once" → scan inserts a single elementId-0 row → promote re-checks and
     * queues one objectless send → log row lands. Cleans up after.
     */
    public function actionOncetest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $channel = $this->firstEmailChannel();
        if (!$channel) {
            $this->stderr("FAIL: no channel configured\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rand = strtolower(StringHelper::randomString(6));
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $recipient = "oncetest_{$rand}@example.test";

        $trigger = new Trigger();
        $trigger->title = 'Oncetest';
        $trigger->handle = "oncetest{$rand}";
        $trigger->triggerMode = 'date';
        $trigger->fixedDate = (new \DateTime('now', $tz))->format('Y-m-d');
        $trigger->dateAudience = 'once';
        $trigger->dateOffsetDays = 0;
        $trigger->dateSendTime = (new \DateTime('now', $tz))->format('H:i');
        $trigger->channelIds = [$channel->uid];
        $trigger->recipients = $recipient;
        $trigger->enabled = true;
        if (!$elements->saveElement($trigger)) {
            $this->stderr('FAIL: save trigger: ' . json_encode($trigger->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $inserted = $plugin->scheduler->scan();
        $row = ScheduledSendRecord::find()
            ->where(['triggerId' => $trigger->id, 'elementId' => 0])
            ->one();
        $this->stdout(sprintf("  [%s] scan scheduled the once-send (inserted={$inserted})\n", $row ? 'PASS' : 'FAIL'));

        $result = $plugin->scheduler->promote();
        $this->stdout(sprintf(
            "  [%s] promote re-checked and queued (sent={$result['sent']} skipped={$result['skipped']})\n",
            $result['sent'] >= 1 ? 'PASS' : 'FAIL',
        ));

        Craft::$app->getQueue()->run();

        $log = EmailLogRecord::find()->where(['triggerUid' => $trigger->uid])->one();
        $ok = $row !== null && $result['sent'] >= 1 && $log !== null && $log->recipient === $recipient;
        if ($log) {
            $this->stdout(sprintf(
                "  [%s] log row: channel=%s status=%s recipient=%s\n",
                $ok ? 'PASS' : 'FAIL', $log->channel, $log->status, $log->recipient,
            ));
        } else {
            $this->stderr("  [FAIL] no log row recorded\n");
        }

        // Re-scan must NOT reschedule (the processed elementId-0 row is the marker)
        $plugin->scheduler->scan();
        $again = ScheduledSendRecord::find()
            ->where(['triggerId' => $trigger->id, 'elementId' => 0, 'processedAt' => null])
            ->exists();
        $this->stdout(sprintf("  [%s] re-scan didn't reschedule (once per date)\n", $again ? 'FAIL' : 'PASS'));
        $ok = $ok && !$again;

        if ($log) {
            $log->delete();
        }
        if ($tpl = $trigger->getTemplate()) {
            $elements->deleteElement($tpl, true);
        }
        $elements->deleteElement($trigger, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * E2E for per-element fixed-date triggers (the investiture shape): fixed
     * date = today, audience "elements", a condition scoping the cohort to one
     * scratch user → scan prefilters candidates via the condition groups and
     * schedules exactly one row → promote queues → log row lands. Cleans up.
     */
    public function actionFixedtest(): int
    {
        $plugin = Courier::$plugin;
        $elements = Craft::$app->getElements();
        $channel = $this->firstEmailChannel();
        if (!$channel) {
            $this->stderr("FAIL: no channel configured\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rand = strtolower(StringHelper::randomString(6));
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());

        // The cohort of one
        $user = new User();
        $user->username = "fixedtest_{$rand}";
        $user->email = "fixedtest_{$rand}@example.test";
        if (!$elements->saveElement($user)) {
            $this->stderr('FAIL: save user: ' . json_encode($user->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $group = new CourierSendCondition();
        $group->elementType = User::class;
        $group->addConditionRule(Craft::$app->getConditions()->createConditionRule([
            'class' => EmailConditionRule::class,
            'value' => $user->email,
        ]));

        $trigger = new Trigger();
        $trigger->title = 'Fixedtest';
        $trigger->handle = "fixedtest{$rand}";
        $trigger->triggerMode = 'date';
        $trigger->dateElementType = User::class;
        $trigger->fixedDate = (new \DateTime('now', $tz))->format('Y-m-d');
        $trigger->dateAudience = 'elements';
        $trigger->dateOffsetDays = 0;
        $trigger->dateSendTime = (new \DateTime('now', $tz))->format('H:i');
        $trigger->channelIds = [$channel->uid];
        $trigger->recipients = '{{ user.email }}';
        $trigger->condition = Json::encode(['groups' => [$group->getConfig()]]);
        $trigger->enabled = true;
        if (!$elements->saveElement($trigger)) {
            $this->stderr('FAIL: save trigger: ' . json_encode($trigger->getErrors()) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin->scheduler->scan();
        $rowCount = (int) ScheduledSendRecord::find()->where(['triggerId' => $trigger->id])->count();
        $row = ScheduledSendRecord::find()
            ->where(['triggerId' => $trigger->id, 'elementId' => $user->id])
            ->one();
        $this->stdout(sprintf(
            "  [%s] scan scheduled exactly the cohort (rows={$rowCount}, scratch user %s)\n",
            ($rowCount === 1 && $row) ? 'PASS' : 'FAIL',
            $row ? 'matched' : 'missing',
        ));

        $result = $plugin->scheduler->promote();
        $this->stdout(sprintf(
            "  [%s] promote re-checked and queued (sent={$result['sent']} skipped={$result['skipped']})\n",
            $result['sent'] >= 1 ? 'PASS' : 'FAIL',
        ));

        Craft::$app->getQueue()->run();

        $log = EmailLogRecord::find()->where(['triggerUid' => $trigger->uid])->one();
        $ok = $rowCount === 1 && $row !== null && $result['sent'] >= 1
            && $log !== null && $log->recipient === $user->email;
        if ($log) {
            $this->stdout(sprintf(
                "  [%s] log row: channel=%s status=%s recipient=%s\n",
                $ok ? 'PASS' : 'FAIL', $log->channel, $log->status, $log->recipient,
            ));
        } else {
            $this->stderr("  [FAIL] no log row recorded\n");
        }

        // Re-scan must NOT reschedule
        $plugin->scheduler->scan();
        $again = ScheduledSendRecord::find()
            ->where(['triggerId' => $trigger->id, 'processedAt' => null])
            ->exists();
        $this->stdout(sprintf("  [%s] re-scan didn't reschedule\n", $again ? 'FAIL' : 'PASS'));
        $ok = $ok && !$again;

        if ($log) {
            $log->delete();
        }
        $elements->deleteElement($user, true);
        if ($tpl = $trigger->getTemplate()) {
            $elements->deleteElement($tpl, true);
        }
        $elements->deleteElement($trigger, true);
        $this->stdout("cleaned up\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
