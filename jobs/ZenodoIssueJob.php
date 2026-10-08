<?php

namespace APP\plugins\generic\zenodo\jobs;

use APP\notification\NotificationManager;
use APP\plugins\generic\zenodo\ZenodoIssueService;
use APP\plugins\generic\zenodo\ZenodoPlugin;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PKP\jobs\BaseJob;
use PKP\notification\Notification;
use PKP\plugins\PluginRegistry;

class ZenodoIssueJob extends BaseJob implements ShouldBeUnique
{
    private const LOCK_EXPIRES_AFTER_SECONDS = 3600;

    public $tries = 1;
    public int $timeout = 3600;

    protected int $issueId;
    protected int $contextId;
    protected ?int $userId;

    public function __construct(int $issueId, int $contextId, ?int $userId = null)
    {
        parent::__construct();
        $this->issueId = $issueId;
        $this->contextId = $contextId;
        $this->userId = $userId;
    }

    public function uniqueId(): string
    {
        return 'issue:' . $this->contextId . ':' . $this->issueId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->dontRelease()
                ->expireAfter(self::LOCK_EXPIRES_AFTER_SECONDS),
        ];
    }

    public function handle(): void
    {
        $plugin = $this->ensurePluginIsLoaded();
        if (!$plugin || !$plugin->getEnabled($this->contextId)) {
            return;
        }

        $plugin->saveIssueJobStatus($this->contextId, $this->issueId, [
            'state' => 'running',
            'message' => __('plugins.generic.zenodo.issue.job.running'),
            'updatedAt' => date('c'),
        ]);

        $result = (new ZenodoIssueService($plugin))->run($this->contextId, $this->issueId);

        $plugin->saveIssueJobStatus($this->contextId, $this->issueId, [
            'state' => 'success',
            'message' => __('plugins.generic.zenodo.issue.job.success'),
            'recordId' => (string) ($result['id'] ?? ''),
            'doi' => (string) ($result['doi'] ?? ''),
            'articleCount' => (int) ($result['articleCount'] ?? 0),
            'updatedAt' => date('c'),
        ]);

        if ($this->userId) {
            (new NotificationManager())->createTrivialNotification(
                $this->userId,
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('plugins.generic.zenodo.issue.notification.published', [
                    'id' => (string) ($result['id'] ?? ''),
                    'doi' => (string) ($result['doi'] ?? ''),
                ])]
            );
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        $plugin = $this->ensurePluginIsLoaded();
        if (!$plugin) {
            return;
        }

        $message = $exception ? $exception->getMessage() : __('plugins.generic.zenodo.issue.job.failed');
        error_log('Zenodo issue background job failed for issue ' . $this->issueId . ': ' . $message);

        $plugin->saveIssueJobStatus($this->contextId, $this->issueId, [
            'state' => 'failed',
            'message' => $message,
            'updatedAt' => date('c'),
        ]);

        if ($this->userId) {
            (new NotificationManager())->createTrivialNotification(
                $this->userId,
                Notification::NOTIFICATION_TYPE_ERROR,
                ['contents' => __('plugins.generic.zenodo.issue.notification.failed', [
                    'message' => $message,
                ])]
            );
        }
    }

    private function ensurePluginIsLoaded(): ?ZenodoPlugin
    {
        $plugin = PluginRegistry::getPlugin('generic', 'zenodoplugin')
            ?? PluginRegistry::loadPlugin('generic', 'zenodo', $this->contextId);

        return $plugin instanceof ZenodoPlugin ? $plugin : null;
    }
}
