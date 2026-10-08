<?php

namespace APP\plugins\generic\zenodo;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\generic\zenodo\jobs\ZenodoDepositJob;
use APP\plugins\generic\zenodo\jobs\ZenodoIssueJob;
use APP\plugins\generic\zenodo\jobs\ZenodoIssueBackfillJob;
use PKP\core\JSONMessage;
use PKP\db\DAO;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\Notification;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class ZenodoPlugin extends GenericPlugin
{
    public const VERSION = '1.6.0';

    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }
        if (Application::isUnderMaintenance()) {
            return true;
        }
        if (!$this->getEnabled($mainContextId)) {
            return true;
        }

        // OJS 3.5 publication hook. This fires for standalone publication and
        // for every scheduled article when an issue is published.
        Hook::add('Publication::publish', $this->publicationPublished(...));

        // OJS issue publication hook. The issue object already has published=1
        // and datePublished set when this hook is invoked. The queued job runs
        // after the request and re-fetches canonical state from the repository.
        Hook::add('IssueGridHandler::publishIssue', $this->issuePublished(...));

        return true;
    }

    public function publicationPublished(string $hookName, array $args): bool
    {
        $publication = $args[0] ?? null;
        if (!$publication || !is_object($publication)) {
            return Hook::CONTINUE;
        }

        // STATUS_PUBLISHED is 3 in OJS/PKP, but compare to the class constant
        // when it is available so the plugin stays readable across 3.5 builds.
        $status = (int) $publication->getData('status');
        $publishedStatus = defined('APP\\publication\\Publication::STATUS_PUBLISHED')
            ? \APP\publication\Publication::STATUS_PUBLISHED
            : 3;
        if ($status !== (int) $publishedStatus) {
            return Hook::CONTINUE;
        }

        $submissionId = (int) $publication->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            return Hook::CONTINUE;
        }
        $contextId = (int) $submission->getData('contextId');
        if (!$contextId || !$this->getEnabled($contextId) || !$this->getAutomaticArticlePublish($contextId)) {
            return Hook::CONTINUE;
        }

        $userId = null;
        try {
            $user = Application::get()->getRequest()->getUser();
            $userId = $user ? (int) $user->getId() : null;
        } catch (\Throwable $ignored) {
        }

        $this->saveJobStatus($contextId, $submissionId, [
            'state' => 'queued',
            'message' => __('plugins.generic.zenodo.job.autoQueued'),
            'updatedAt' => date('c'),
        ]);

        try {
            dispatch(new ZenodoDepositJob($submissionId, $contextId, $userId));
        } catch (\Throwable $e) {
            error_log('Zenodo automatic article queue dispatch failed: ' . $e->getMessage());
            $this->saveJobStatus($contextId, $submissionId, [
                'state' => 'failed',
                'message' => $e->getMessage(),
                'updatedAt' => date('c'),
            ]);
        }

        return Hook::CONTINUE;
    }

    public function issuePublished(string $hookName, array $args): bool
    {
        $issue = $args[0] ?? null;
        if (!$issue || !is_object($issue) || !(bool) $issue->getData('published')) {
            return Hook::CONTINUE;
        }

        $issueId = (int) $issue->getId();
        $contextId = (int) $issue->getJournalId();
        if (!$issueId || !$contextId || !$this->getEnabled($contextId) || !$this->getAutomaticIssuePublish($contextId)) {
            return Hook::CONTINUE;
        }

        $userId = null;
        try {
            $user = Application::get()->getRequest()->getUser();
            $userId = $user ? (int) $user->getId() : null;
        } catch (\Throwable $ignored) {
        }

        $this->saveIssueJobStatus($contextId, $issueId, [
            'state' => 'queued',
            'message' => __('plugins.generic.zenodo.issue.job.queued'),
            'updatedAt' => date('c'),
        ]);

        try {
            dispatch(new ZenodoIssueJob($issueId, $contextId, $userId));
        } catch (\Throwable $e) {
            error_log('Zenodo automatic issue queue dispatch failed: ' . $e->getMessage());
            $this->saveIssueJobStatus($contextId, $issueId, [
                'state' => 'failed',
                'message' => $e->getMessage(),
                'updatedAt' => date('c'),
            ]);
        }

        return Hook::CONTINUE;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.zenodo.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.zenodo.description');
    }

    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $settingsUrl = $router->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        $depositUrl = $router->url($request, null, null, 'manage', null, [
            'verb' => 'deposit',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        $backfillUrl = $router->url($request, null, null, 'manage', null, [
            'verb' => 'backfillIssues',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);

        array_unshift(
            $actions,
            new LinkAction(
                'backfillIssues',
                new AjaxModal($backfillUrl, __('plugins.generic.zenodo.backfill.title')),
                __('plugins.generic.zenodo.backfill.actionLink')
            )
        );
        array_unshift(
            $actions,
            new LinkAction(
                'deposit',
                new AjaxModal($depositUrl, __('plugins.generic.zenodo.deposit.title')),
                __('plugins.generic.zenodo.deposit.actionLink')
            )
        );
        array_unshift(
            $actions,
            new LinkAction(
                'settings',
                new AjaxModal($settingsUrl, $this->getDisplayName()),
                __('manager.plugins.settings')
            )
        );

        return $actions;
    }

    public function manage($args, $request): JSONMessage
    {
        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.zenodo.error.contextRequired'));
        }
        $contextId = (int) $context->getId();

        switch ((string) $request->getUserVar('verb')) {
            case 'settings':
                return $this->manageSettings($request, $contextId);
            case 'deposit':
                return $this->manageDeposit($request, $contextId);
            case 'backfillIssues':
                return $this->manageIssueBackfill($request, $contextId);
            default:
                return parent::manage($args, $request);
        }
    }

    private function manageSettings($request, int $contextId): JSONMessage
    {
        $form = new ZenodoSettingsForm($this, $contextId);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        (new NotificationManager())->createTrivialNotification(
            $request->getUser()->getId(),
            Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('common.changesSaved')]
        );
        return DAO::getDataChangedEvent();
    }

    private function manageDeposit($request, int $contextId): JSONMessage
    {
        $form = new ZenodoDepositForm($this, $contextId);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $submissionId = (int) $form->getData('submissionId');
        $user = $request->getUser();
        if (!$user) {
            return new JSONMessage(false, __('plugins.generic.zenodo.error.loginRequired'));
        }
        $userId = (int) $user->getId();

        $this->updateSetting($contextId, 'lastSubmissionId', $submissionId, 'int');
        $this->saveJobStatus($contextId, $submissionId, [
            'state' => 'queued',
            'message' => __('plugins.generic.zenodo.job.queued'),
            'updatedAt' => date('c'),
        ]);

        try {
            dispatch(new ZenodoDepositJob($submissionId, $contextId, $userId));
        } catch (\Throwable $e) {
            $this->saveJobStatus($contextId, $submissionId, [
                'state' => 'failed',
                'message' => $e->getMessage(),
                'updatedAt' => date('c'),
            ]);
            error_log('Zenodo queue dispatch failed: ' . $e->getMessage());
            (new NotificationManager())->createTrivialNotification(
                $userId,
                Notification::NOTIFICATION_TYPE_ERROR,
                ['contents' => __('plugins.generic.zenodo.notification.failed', [
                    'message' => $e->getMessage(),
                ])]
            );
            return DAO::getDataChangedEvent();
        }

        (new NotificationManager())->createTrivialNotification(
            $userId,
            Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('plugins.generic.zenodo.notification.queued', [
                'id' => (string) $submissionId,
            ])]
        );
        return DAO::getDataChangedEvent();
    }

    private function manageIssueBackfill($request, int $contextId): JSONMessage
    {
        $form = new ZenodoIssueBackfillForm($this, $contextId);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $user = $request->getUser();
        if (!$user) {
            return new JSONMessage(false, __('plugins.generic.zenodo.error.loginRequired'));
        }
        $userId = (int) $user->getId();
        $mode = (string) $form->getData('backfillMode');

        if ($mode === 'one') {
            $issueId = (int) $form->getData('issueId');
            $this->updateSetting($contextId, 'lastBackfillIssueId', $issueId, 'int');
            $this->saveIssueJobStatus($contextId, $issueId, [
                'state' => 'queued',
                'message' => __('plugins.generic.zenodo.issue.job.queued'),
                'updatedAt' => date('c'),
            ]);

            try {
                dispatch(new ZenodoIssueJob($issueId, $contextId, $userId));
            } catch (\Throwable $e) {
                $this->saveIssueJobStatus($contextId, $issueId, [
                    'state' => 'failed',
                    'message' => $e->getMessage(),
                    'updatedAt' => date('c'),
                ]);
                error_log('Zenodo single-issue backfill dispatch failed: ' . $e->getMessage());
                (new NotificationManager())->createTrivialNotification(
                    $userId,
                    Notification::NOTIFICATION_TYPE_ERROR,
                    ['contents' => __('plugins.generic.zenodo.backfill.notification.failed', [
                        'message' => $e->getMessage(),
                    ])]
                );
                return DAO::getDataChangedEvent();
            }

            (new NotificationManager())->createTrivialNotification(
                $userId,
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('plugins.generic.zenodo.backfill.notification.oneQueued', [
                    'id' => (string) $issueId,
                ])]
            );
            return DAO::getDataChangedEvent();
        }

        $rows = [];
        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByPublished(true)
            ->getMany();

        foreach ($issues as $issue) {
            $issueId = (int) $issue->getId();
            $saved = $this->getIssueDeposit($contextId, $issueId);
            if (
                is_array($saved)
                && strtolower((string) ($saved['status'] ?? '')) === 'published'
                && trim((string) ($saved['doi'] ?? '')) !== ''
            ) {
                continue;
            }

            $date = trim((string) $issue->getData('datePublished'));
            $rows[] = [
                'id' => $issueId,
                'timestamp' => $date !== '' && strtotime($date) !== false ? strtotime($date) : 0,
            ];
        }

        // Queue the historical run oldest-to-newest. A single background job
        // processes the list serially so issue/article DOI relationships remain
        // deterministic even when a site has more than one queue worker.
        usort($rows, static function (array $a, array $b): int {
            if ($a['timestamp'] === $b['timestamp']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['timestamp'] <=> $b['timestamp'];
        });
        $issueIds = array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));

        if (!$issueIds) {
            (new NotificationManager())->createTrivialNotification(
                $userId,
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('plugins.generic.zenodo.backfill.notification.none')]
            );
            return DAO::getDataChangedEvent();
        }

        $this->saveIssueBackfillStatus($contextId, [
            'state' => 'queued',
            'message' => __('plugins.generic.zenodo.backfill.job.queued'),
            'total' => count($issueIds),
            'completed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'updatedAt' => date('c'),
        ]);

        try {
            dispatch(new ZenodoIssueBackfillJob($contextId, $issueIds, $userId));
        } catch (\Throwable $e) {
            $this->saveIssueBackfillStatus($contextId, [
                'state' => 'failed',
                'message' => $e->getMessage(),
                'updatedAt' => date('c'),
            ]);
            error_log('Zenodo all-issues backfill dispatch failed: ' . $e->getMessage());
            (new NotificationManager())->createTrivialNotification(
                $userId,
                Notification::NOTIFICATION_TYPE_ERROR,
                ['contents' => __('plugins.generic.zenodo.backfill.notification.failed', [
                    'message' => $e->getMessage(),
                ])]
            );
            return DAO::getDataChangedEvent();
        }

        (new NotificationManager())->createTrivialNotification(
            $userId,
            Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('plugins.generic.zenodo.backfill.notification.allQueued', [
                'count' => (string) count($issueIds),
            ])]
        );
        return DAO::getDataChangedEvent();
    }

    public function getIssueBackfillStatus(int $contextId): ?array
    {
        $value = $this->getSetting($contextId, 'issue_backfill_job');
        if (!$value) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveIssueBackfillStatus(int $contextId, array $data): void
    {
        $this->updateSetting(
            $contextId,
            'issue_backfill_job',
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'string'
        );
    }

    public function getEnvironment(int $contextId): string
    {
        return $this->getSetting($contextId, 'environment') === 'production'
            ? 'production'
            : 'sandbox';
    }

    public function getPublisher(int $contextId): string
    {
        $value = trim((string) $this->getSetting($contextId, 'publisher'));
        return $value !== '' ? $value : 'Tejo Prabha Foundation';
    }

    public function getJournalIssn(int $contextId): string
    {
        $value = strtoupper(trim((string) $this->getSetting($contextId, 'journalIssn')));
        return $value !== '' ? $value : '3139-7700';
    }

    public function getResourceVersion(int $contextId): string
    {
        $value = trim((string) $this->getSetting($contextId, 'resourceVersion'));
        return $value !== '' ? $value : '1.0';
    }

    public function getLanguageCode(int $contextId): string
    {
        $value = strtolower(trim((string) $this->getSetting($contextId, 'languageCode')));
        return preg_match('/^[a-z]{3}$/', $value) ? $value : 'eng';
    }

    public function getStoreReservedDoi(int $contextId): bool
    {
        $value = $this->getSetting($contextId, 'storeReservedDoi');
        return $value === null || $value === '' ? true : (bool) $value;
    }

    public function getAutomaticArticlePublish(?int $contextId = null): bool
    {
        $value = $this->getSetting($contextId, 'automaticArticlePublish');
        return $value === null || $value === '' ? true : (bool) $value;
    }

    public function getAutomaticIssuePublish(?int $contextId = null): bool
    {
        $value = $this->getSetting($contextId, 'automaticIssuePublish');
        return $value === null || $value === '' ? true : (bool) $value;
    }

    public function hasApiToken(int $contextId): bool
    {
        return trim((string) $this->getSetting($contextId, 'apiToken')) !== '';
    }

    public function getApi(int $contextId): ZenodoApi
    {
        $token = trim((string) $this->getSetting($contextId, 'apiToken'));
        if ($token === '') {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.tokenMissing'));
        }

        $baseUrl = $this->getEnvironment($contextId) === 'production'
            ? 'https://zenodo.org/api'
            : 'https://sandbox.zenodo.org/api';

        return new ZenodoApi($baseUrl, $token);
    }

    public function getJobStatus(int $contextId, int $submissionId): ?array
    {
        $value = $this->getSetting($contextId, 'job_' . $submissionId);
        if (!$value) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveJobStatus(int $contextId, int $submissionId, array $data): void
    {
        $this->updateSetting(
            $contextId,
            'job_' . $submissionId,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'string'
        );
    }

    public function getDeposit(int $contextId, int $submissionId): ?array
    {
        $value = $this->getSetting($contextId, 'deposit_' . $submissionId);
        if (!$value) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveDeposit(int $contextId, int $submissionId, array $data): void
    {
        $this->updateSetting(
            $contextId,
            'deposit_' . $submissionId,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'string'
        );
    }

    public function getIssueJobStatus(int $contextId, int $issueId): ?array
    {
        $value = $this->getSetting($contextId, 'issue_job_' . $issueId);
        if (!$value) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveIssueJobStatus(int $contextId, int $issueId, array $data): void
    {
        $this->updateSetting(
            $contextId,
            'issue_job_' . $issueId,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'string'
        );
    }

    public function getIssueDeposit(int $contextId, int $issueId): ?array
    {
        $value = $this->getSetting($contextId, 'issue_deposit_' . $issueId);
        if (!$value) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveIssueDeposit(int $contextId, int $issueId, array $data): void
    {
        $this->updateSetting(
            $contextId,
            'issue_deposit_' . $issueId,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'string'
        );
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\\APP\\plugins\\generic\\zenodo\\ZenodoPlugin', '\\ZenodoPlugin');
}
