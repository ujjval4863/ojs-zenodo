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

class ZenodoIssueBackfillJob extends BaseJob implements ShouldBeUnique
{
    private const LOCK_EXPIRES_AFTER_SECONDS = 14400;

    public $tries = 1;
    public int $timeout = 14400;

    protected int $contextId;
    protected ?int $userId;
    protected array $issueIds;

    public function __construct(int $contextId, array $issueIds, ?int $userId = null)
    {
        parent::__construct();
        $this->contextId = $contextId;
        $this->issueIds = array_values(array_unique(array_filter(array_map('intval', $issueIds), static fn(int $id): bool => $id > 0)));
        $this->userId = $userId;
    }

    public function uniqueId(): string
    {
        return 'issue-backfill:' . $this->contextId;
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

        $total = count($this->issueIds);
        $completed = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        $plugin->saveIssueBackfillStatus($this->contextId, [
            'state' => 'running',
            'message' => __('plugins.generic.zenodo.backfill.job.running'),
            'total' => $total,
            'completed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'updatedAt' => date('c'),
        ]);

        foreach ($this->issueIds as $issueId) {
            $saved = $plugin->getIssueDeposit($this->contextId, $issueId);
            if (
                is_array($saved)
                && strtolower((string) ($saved['status'] ?? '')) === 'published'
                && trim((string) ($saved['doi'] ?? '')) !== ''
            ) {
                $skipped++;
                $completed++;
                $this->saveProgress($plugin, $total, $completed, $skipped, $failed, $errors);
                continue;
            }

            $plugin->saveIssueJobStatus($this->contextId, $issueId, [
                'state' => 'running',
                'message' => __('plugins.generic.zenodo.issue.job.running'),
                'updatedAt' => date('c'),
            ]);

            try {
                $result = (new ZenodoIssueService($plugin))->run($this->contextId, $issueId);
                $plugin->saveIssueJobStatus($this->contextId, $issueId, [
                    'state' => 'success',
                    'message' => __('plugins.generic.zenodo.issue.job.success'),
                    'recordId' => (string) ($result['id'] ?? ''),
                    'doi' => (string) ($result['doi'] ?? ''),
                    'articleCount' => (int) ($result['articleCount'] ?? 0),
                    'updatedAt' => date('c'),
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'issueId' => $issueId,
                    'message' => $e->getMessage(),
                ];
                $plugin->saveIssueJobStatus($this->contextId, $issueId, [
                    'state' => 'failed',
                    'message' => $e->getMessage(),
                    'updatedAt' => date('c'),
                ]);
                error_log('Zenodo issue backfill failed for issue ' . $issueId . ': ' . $e->getMessage());
            }

            $completed++;
            $this->saveProgress($plugin, $total, $completed, $skipped, $failed, $errors);
        }

        $state = $failed > 0 ? 'partial' : 'success';
        $message = $failed > 0
            ? __('plugins.generic.zenodo.backfill.job.partial', [
                'completed' => (string) ($completed - $failed),
                'failed' => (string) $failed,
            ])
            : __('plugins.generic.zenodo.backfill.job.success', [
                'completed' => (string) ($completed - $skipped),
                'skipped' => (string) $skipped,
            ]);

        $plugin->saveIssueBackfillStatus($this->contextId, [
            'state' => $state,
            'message' => $message,
            'total' => $total,
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => array_slice($errors, -10),
            'updatedAt' => date('c'),
        ]);

        if ($this->userId) {
            (new NotificationManager())->createTrivialNotification(
                $this->userId,
                $failed > 0 ? Notification::NOTIFICATION_TYPE_ERROR : Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => $message]
            );
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        $plugin = $this->ensurePluginIsLoaded();
        if (!$plugin) {
            return;
        }

        $message = $exception ? $exception->getMessage() : __('plugins.generic.zenodo.backfill.job.failed');
        error_log('Zenodo issue backfill job failed: ' . $message);
        $plugin->saveIssueBackfillStatus($this->contextId, [
            'state' => 'failed',
            'message' => $message,
            'updatedAt' => date('c'),
        ]);

        if ($this->userId) {
            (new NotificationManager())->createTrivialNotification(
                $this->userId,
                Notification::NOTIFICATION_TYPE_ERROR,
                ['contents' => __('plugins.generic.zenodo.backfill.notification.failed', ['message' => $message])]
            );
        }
    }

    private function saveProgress(
        ZenodoPlugin $plugin,
        int $total,
        int $completed,
        int $skipped,
        int $failed,
        array $errors
    ): void {
        $plugin->saveIssueBackfillStatus($this->contextId, [
            'state' => 'running',
            'message' => __('plugins.generic.zenodo.backfill.job.progress', [
                'completed' => (string) $completed,
                'total' => (string) $total,
            ]),
            'total' => $total,
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => array_slice($errors, -10),
            'updatedAt' => date('c'),
        ]);
    }

    private function ensurePluginIsLoaded(): ?ZenodoPlugin
    {
        $plugin = PluginRegistry::getPlugin('generic', 'zenodoplugin')
            ?? PluginRegistry::loadPlugin('generic', 'zenodo', $this->contextId);

        return $plugin instanceof ZenodoPlugin ? $plugin : null;
    }
}
