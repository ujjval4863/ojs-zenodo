<?php

namespace APP\plugins\generic\zenodo\jobs;

use APP\notification\NotificationManager;
use APP\plugins\generic\zenodo\ZenodoDepositService;
use APP\plugins\generic\zenodo\ZenodoPlugin;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PKP\jobs\BaseJob;
use PKP\notification\Notification;
use PKP\plugins\PluginRegistry;

class ZenodoDepositJob extends BaseJob implements ShouldBeUnique
{
    private const LOCK_EXPIRES_AFTER_SECONDS = 540;

    /** Do not retry a failed external publish automatically. */
    public $tries = 1;

    /** Stay below OJS's default queue retry window. */
    public int $timeout = 540;

    protected int $submissionId;
    protected int $contextId;
    protected ?int $userId;

    public function __construct(int $submissionId, int $contextId, ?int $userId = null)
    {
        parent::__construct();
        $this->submissionId = $submissionId;
        $this->contextId = $contextId;
        $this->userId = $userId;
    }

    public function uniqueId(): string
    {
        return $this->contextId . ':' . $this->submissionId;
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

        $plugin->saveJobStatus($this->contextId, $this->submissionId, [
            'state' => 'running',
            'message' => __('plugins.generic.zenodo.job.running'),
            'updatedAt' => date('c'),
        ]);

        $result = (new ZenodoDepositService($plugin))->run(
            $this->contextId,
            $this->submissionId,
            'process'
        );

        $plugin->saveJobStatus($this->contextId, $this->submissionId, [
            'state' => 'success',
            'message' => __('plugins.generic.zenodo.job.success'),
            'recordId' => (string) ($result['id'] ?? ''),
            'doi' => (string) ($result['doi'] ?? ''),
            'updatedAt' => date('c'),
        ]);

        if ($this->userId) {
            (new NotificationManager())->createTrivialNotification(
                $this->userId,
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('plugins.generic.zenodo.notification.backgroundPublished', [
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

        $message = $exception ? $exception->getMessage() : __('plugins.generic.zenodo.job.failed');
        error_log('Zenodo background job failed for submission ' . $this->submissionId . ': ' . $message);

        $plugin->saveJobStatus($this->contextId, $this->submissionId, [
            'state' => 'failed',
            'message' => $message,
            'updatedAt' => date('c'),
        ]);

        if ($this->userId) {
            (new NotificationManager())->createTrivialNotification(
                $this->userId,
                Notification::NOTIFICATION_TYPE_ERROR,
                ['contents' => __('plugins.generic.zenodo.notification.failed', [
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
