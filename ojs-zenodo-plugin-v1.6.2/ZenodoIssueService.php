<?php

namespace APP\plugins\generic\zenodo;

use APP\core\Application;
use APP\facades\Repo;

class ZenodoIssueService
{
    private const API_VERSION = 'rdm-v1';

    public function __construct(private ZenodoPlugin $plugin)
    {
    }

    public function run(int $contextId, int $issueId): array
    {
        return $this->withIssueLock($contextId, $issueId, function () use ($contextId, $issueId): array {
            $issue = Repo::issue()->get($issueId);
            if (!$issue) {
                throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.notFound'));
            }

            $journalId = method_exists($issue, 'getJournalId')
                ? (int) $issue->getJournalId()
                : (int) $issue->getData('journalId');
            if ($journalId !== $contextId) {
                throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.notFound'));
            }
            if (!(bool) $issue->getData('published')) {
                throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.notPublished'));
            }

            $context = Application::getContextDAO()->getById($contextId);
            if (!$context) {
                throw new \RuntimeException(__('plugins.generic.zenodo.error.contextRequired'));
            }

            // The OJS issue-publish hook fires just before scheduled publications
            // are flipped to STATUS_PUBLISHED. A queue worker can theoretically
            // pick this job up immediately, so wait briefly for the parent OJS
            // publication transaction to finish before exporting final metadata.
            $this->waitForIssuePublications($contextId, $issueId);

            // Process every article in the issue first. This guarantees that the
            // issue record can link to a complete set of published article DOIs.
            $articles = $this->processIssueArticles($contextId, $issueId);
            $payload = $this->buildPayload($contextId, $issue, $context, $articles);

            $api = $this->plugin->getApi($contextId);
            $environment = $this->plugin->getEnvironment($contextId);
            $saved = $this->plugin->getIssueDeposit($contextId, $issueId);
            $record = null;

            if (
                $saved
                && !empty($saved['id'])
                && ($saved['environment'] ?? $environment) === $environment
                && ($saved['apiVersion'] ?? self::API_VERSION) === self::API_VERSION
            ) {
                try {
                    $record = $api->getDraft((string) $saved['id']);
                } catch (ZenodoApiException $e) {
                    if ($e->getHttpStatus() !== 404) {
                        throw $e;
                    }
                    try {
                        $published = $api->getRecord((string) $saved['id']);
                        if ($this->isPublished($published)) {
                            return $this->savePublishedState($contextId, $issue, $published, $articles, $saved);
                        }
                    } catch (ZenodoApiException $recordError) {
                        if ($recordError->getHttpStatus() !== 404) {
                            throw $recordError;
                        }
                    }
                }
            }

            if ($record && $this->isPublished($record)) {
                return $this->savePublishedState($contextId, $issue, $record, $articles, $saved ?? []);
            }

            if (!$record) {
                $record = $api->createDraft($payload);
            } else {
                $record = $api->updateDraft((string) $record['id'], $payload);
            }

            $recordId = trim((string) ($record['id'] ?? $record['recid'] ?? ''));
            if ($recordId === '') {
                throw new \RuntimeException(__('plugins.generic.zenodo.error.invalidResponse'));
            }

            // Persist immediately so retries always reuse this issue draft.
            $state = $this->recordToSavedIssue($record, $environment, $articles);
            $state['doiStoredInOjs'] = (bool) ($saved['doiStoredInOjs'] ?? false);
            $this->plugin->saveIssueDeposit($contextId, $issueId, $state);

            $doi = $this->extractDoi($record);
            if ($doi === '') {
                $record = $api->reserveDoi(
                    $recordId,
                    isset($record['links']['reserve_doi']) && is_string($record['links']['reserve_doi'])
                        ? $record['links']['reserve_doi']
                        : null
                );
                $record = $api->getDraft($recordId);
                $doi = $this->extractDoi($record);
            }
            if ($doi === '') {
                throw new \RuntimeException(__('plugins.generic.zenodo.error.doiNotReserved'));
            }

            $state = $this->recordToSavedIssue($record, $environment, $articles);
            $state['doiStoredInOjs'] = (bool) ($saved['doiStoredInOjs'] ?? false);
            $this->plugin->saveIssueDeposit($contextId, $issueId, $state);

            // Zenodo production requires every published record to contain at least
            // one file. Issue records intentionally do not duplicate all article
            // PDFs, so upload a small machine-readable issue manifest instead.
            $manifestFile = $this->uploadAndVerifyIssueManifest(
                $api,
                $record,
                $recordId,
                $issue,
                $context,
                $articles,
                $doi
            );
            $state['manifestFile'] = $manifestFile;
            $this->plugin->saveIssueDeposit($contextId, $issueId, $state);

            try {
                $publishResponse = $api->publishDraft(
                    $recordId,
                    isset($record['links']['publish']) && is_string($record['links']['publish'])
                        ? $record['links']['publish']
                        : null
                );
            } catch (ZenodoApiException $e) {
                if ($e->getHttpStatus() === 403) {
                    throw new \RuntimeException(
                        $e->getMessage() . ' ' . __('plugins.generic.zenodo.error.publishScope'),
                        403,
                        $e
                    );
                }
                throw $e;
            }

            $published = $this->fetchPublishedRecord($api, $recordId, $publishResponse);
            return $this->savePublishedState($contextId, $issue, $published, $articles, $state);
        });
    }

    private function withIssueLock(int $contextId, int $issueId, callable $callback): array
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'ojs-zenodo-issue-' . $contextId . '-' . $issueId . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return $callback();
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.lockFailed'));
            }
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }


    private function waitForIssuePublications(int $contextId, int $issueId): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $allPublished = true;
            $submissions = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByIssueIds([$issueId])
                ->getMany();

            foreach ($submissions as $submission) {
                $publication = $submission->getCurrentPublication();
                if (!$publication || (int) $publication->getData('issueId') !== $issueId) {
                    continue;
                }
                if ((int) $publication->getData('status') !== \APP\publication\Publication::STATUS_PUBLISHED) {
                    $allPublished = false;
                    break;
                }
            }

            if ($allPublished) {
                return;
            }
            usleep(500000);
        }

        throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.articlesNotPublished'));
    }

    private function processIssueArticles(int $contextId, int $issueId): array
    {
        $articles = [];
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByIssueIds([$issueId])
            ->getMany();

        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            if (!$publication || (int) $publication->getData('issueId') !== $issueId) {
                continue;
            }

            try {
                $result = (new ZenodoDepositService($this->plugin))->run(
                    $contextId,
                    (int) $submission->getId(),
                    'process'
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    __('plugins.generic.zenodo.issue.error.articleFailed', [
                        'id' => (string) $submission->getId(),
                        'message' => $e->getMessage(),
                    ]),
                    0,
                    $e
                );
            }

            $doi = trim((string) ($result['doi'] ?? ''));
            if ($doi === '') {
                throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.articleNoDoi', [
                    'id' => (string) $submission->getId(),
                ]));
            }

            $title = method_exists($publication, 'getLocalizedFullTitle')
                ? trim((string) $publication->getLocalizedFullTitle())
                : trim((string) $publication->getLocalizedData('title'));

            $articles[] = [
                'submissionId' => (int) $submission->getId(),
                'title' => $title !== '' ? strip_tags($title) : ('Submission ' . $submission->getId()),
                'doi' => preg_replace('#^https?://(?:dx\\.)?doi\\.org/#i', '', $doi) ?: $doi,
                'zenodoId' => (string) ($result['id'] ?? ''),
                'url' => (string) ($result['url'] ?? ''),
            ];
        }

        return $articles;
    }

    private function buildPayload(int $contextId, $issue, $context, array $articles): array
    {
        $journalName = method_exists($context, 'getLocalizedName')
            ? trim((string) $context->getLocalizedName())
            : trim((string) $context->getLocalizedData('name'));
        if ($journalName === '') {
            $journalName = 'Tark Tansaku';
        }

        $issueIdentification = method_exists($issue, 'getIssueIdentification')
            ? trim((string) $issue->getIssueIdentification())
            : '';
        $issueTitle = method_exists($issue, 'getLocalizedTitle')
            ? trim((string) $issue->getLocalizedTitle())
            : trim((string) $issue->getLocalizedData('title'));

        $titleParts = [$journalName];
        if ($issueIdentification !== '') {
            $titleParts[] = $issueIdentification;
        } elseif ($issueTitle !== '') {
            $titleParts[] = $issueTitle;
        } else {
            $titleParts[] = 'Issue ' . $issue->getId();
        }
        $title = implode(' — ', array_values(array_unique($titleParts)));

        $datePublished = $this->dateOnly($issue->getData('datePublished')) ?: date('Y-m-d');
        $publisher = $this->plugin->getPublisher($contextId);
        $issn = $this->plugin->getJournalIssn($contextId);

        $descriptionParts = [];
        $issueDescription = method_exists($issue, 'getLocalizedDescription')
            ? trim((string) $issue->getLocalizedDescription())
            : trim((string) $issue->getLocalizedData('description'));
        if ($issueDescription !== '') {
            $descriptionParts[] = $issueDescription;
        }
        $descriptionParts[] = '<p>Published issue of <strong>' . htmlspecialchars($journalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>'
            . ($issn !== '' ? ' (ISSN ' . htmlspecialchars($issn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '')
            . '.</p>';
        if ($articles) {
            $items = [];
            foreach ($articles as $article) {
                $items[] = '<li>'
                    . htmlspecialchars((string) $article['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . ' — DOI: <a href="https://doi.org/' . rawurlencode((string) $article['doi']) . '">'
                    . htmlspecialchars((string) $article['doi'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</a></li>';
            }
            $descriptionParts[] = '<p>Articles in this issue:</p><ol>' . implode('', $items) . '</ol>';
        }

        $metadata = [
            // Zenodo/InvenioRDM has no dedicated journal-issue resource type.
            // Use publication-other and carry journal/issue metadata explicitly.
            'resource_type' => ['id' => 'publication-other'],
            'title' => $title,
            'publication_date' => $datePublished,
            'creators' => [[
                'person_or_org' => [
                    'type' => 'organizational',
                    'name' => $journalName,
                ],
            ]],
            'description' => implode("\n", $descriptionParts),
            'publisher' => $publisher,
            'version' => $this->plugin->getResourceVersion($contextId),
            'languages' => [['id' => $this->plugin->getLanguageCode($contextId)]],
            'subjects' => [
                ['subject' => $journalName],
                ['subject' => 'Journal issue'],
            ],
        ];

        if ($issn !== '') {
            $metadata['identifiers'] = [[
                'identifier' => $issn,
                'scheme' => 'issn',
            ]];
        }

        if ($articles) {
            $metadata['related_identifiers'] = array_map(
                static fn(array $article): array => [
                    'identifier' => (string) $article['doi'],
                    'scheme' => 'doi',
                    'relation_type' => ['id' => 'haspart'],
                    'resource_type' => ['id' => 'publication-article'],
                ],
                $articles
            );
        }

        $journal = ['title' => $journalName];
        if ($issn !== '') {
            $journal['issn'] = $issn;
        }
        $volume = trim((string) $issue->getData('volume'));
        $number = trim((string) $issue->getData('number'));
        if ($volume !== '') {
            $journal['volume'] = $volume;
        }
        if ($number !== '') {
            $journal['issue'] = $number;
        }

        $payload = [
            'access' => ['record' => 'public', 'files' => 'public'],
            'files' => ['enabled' => true],
            'metadata' => $metadata,
            'custom_fields' => ['journal:journal' => $journal],
        ];

        $existingDoi = $this->getIssueDoi($issue);
        if ($existingDoi !== '') {
            $payload['pids'] = [
                'doi' => [
                    'identifier' => $existingDoi,
                    'provider' => 'external',
                ],
            ];
        }

        return $payload;
    }

    private function uploadAndVerifyIssueManifest(
        ZenodoApi $api,
        array $record,
        string $recordId,
        $issue,
        $context,
        array $articles,
        string $doi
    ): string {
        $filename = 'issue-' . (int) $issue->getId() . '-manifest.json';
        $journalName = method_exists($context, 'getLocalizedName')
            ? trim((string) $context->getLocalizedName())
            : trim((string) $context->getLocalizedData('name'));
        if ($journalName === '') {
            $journalName = 'Tark Tansaku';
        }

        $manifest = [
            'schema' => 'https://tarktansaku.org/zenodo/issue-manifest/v1',
            'generatedAt' => date('c'),
            'journal' => [
                'title' => $journalName,
                'issn' => $this->plugin->getJournalIssn((int) $context->getId()),
                'publisher' => $this->plugin->getPublisher((int) $context->getId()),
            ],
            'issue' => [
                'ojsIssueId' => (int) $issue->getId(),
                'identification' => method_exists($issue, 'getIssueIdentification')
                    ? trim((string) $issue->getIssueIdentification())
                    : '',
                'title' => method_exists($issue, 'getLocalizedTitle')
                    ? trim((string) $issue->getLocalizedTitle())
                    : trim((string) $issue->getLocalizedData('title')),
                'volume' => trim((string) $issue->getData('volume')),
                'number' => trim((string) $issue->getData('number')),
                'publicationDate' => $this->dateOnly($issue->getData('datePublished')),
                'doi' => $doi,
            ],
            'articles' => array_map(
                static fn(array $article): array => [
                    'submissionId' => (int) ($article['submissionId'] ?? 0),
                    'title' => (string) ($article['title'] ?? ''),
                    'doi' => (string) ($article['doi'] ?? ''),
                    'zenodoId' => (string) ($article['zenodoId'] ?? ''),
                    'url' => (string) ($article['url'] ?? ''),
                ],
                $articles
            ),
        ];

        $path = tempnam(sys_get_temp_dir(), 'ojs-zenodo-issue-');
        if ($path === false) {
            throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.manifestCreate'));
        }

        try {
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($path, $json . "\n") === false) {
                throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.manifestCreate'));
            }

            $entries = $this->issueFileEntries($api->listDraftFiles($recordId));
            foreach ($entries as $entry) {
                if ((string) ($entry['key'] ?? '') === $filename) {
                    try {
                        $api->deleteDraftFile($recordId, $filename);
                    } catch (ZenodoApiException $e) {
                        if ($e->getHttpStatus() !== 404) {
                            throw $e;
                        }
                    }
                    break;
                }
            }

            $initialized = $api->initializeDraftFile(
                $recordId,
                $filename,
                isset($record['links']['files']) && is_string($record['links']['files'])
                    ? $record['links']['files']
                    : null
            );
            $contentUrl = $initialized['links']['content'] ?? null;
            $commitUrl = $initialized['links']['commit'] ?? null;
            if (!is_string($contentUrl) || $contentUrl === '' || !is_string($commitUrl) || $commitUrl === '') {
                throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.manifestLinks'));
            }

            $api->uploadDraftFileContent($contentUrl, $path);
            $api->commitDraftFile($commitUrl);

            for ($attempt = 0; $attempt < 24; $attempt++) {
                if ($attempt > 0) {
                    sleep(2);
                }
                foreach ($this->issueFileEntries($api->listDraftFiles($recordId)) as $entry) {
                    if ((string) ($entry['key'] ?? '') !== $filename) {
                        continue;
                    }
                    $size = (int) ($entry['size'] ?? 0);
                    $status = strtolower(trim((string) ($entry['status'] ?? '')));
                    $checksum = trim((string) ($entry['checksum'] ?? ''));
                    if ($status === 'failed') {
                        throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.manifestFailed'));
                    }
                    if (
                        $size > 0
                        && (
                            in_array($status, ['completed', 'done', 'ready'], true)
                            || ($status === '' && $checksum !== '')
                            || ($checksum !== '' && $status !== 'pending')
                        )
                    ) {
                        return $filename;
                    }
                }
            }

            throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.manifestVerify'));
        } finally {
            @unlink($path);
        }
    }

    private function issueFileEntries(array $response): array
    {
        if (isset($response['entries']) && is_array($response['entries'])) {
            return array_values(array_filter($response['entries'], 'is_array'));
        }
        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }
        return [];
    }

    private function savePublishedState(int $contextId, $issue, array $record, array $articles, array $previous): array
    {
        $doi = $this->extractDoi($record);
        $stored = $doi !== '' ? $this->storeDoiInOjsIfAllowed($contextId, $issue, $doi) : false;
        $state = $this->recordToSavedIssue(
            $record,
            $this->plugin->getEnvironment($contextId),
            $articles
        );
        $state['doiStoredInOjs'] = $stored || (bool) ($previous['doiStoredInOjs'] ?? false);
        if (!empty($previous['manifestFile'])) {
            $state['manifestFile'] = (string) $previous['manifestFile'];
        }
        $this->plugin->saveIssueDeposit($contextId, (int) $issue->getId(), $state);
        return $state;
    }

    private function fetchPublishedRecord(ZenodoApi $api, string $id, array $publishResponse): array
    {
        if ($this->isPublished($publishResponse) && trim((string) ($publishResponse['id'] ?? '')) !== '') {
            return $publishResponse;
        }

        $lastError = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($attempt > 0) {
                usleep(400000);
            }
            try {
                $record = $api->getRecord($id);
                if ($this->isPublished($record)) {
                    return $record;
                }
            } catch (ZenodoApiException $e) {
                $lastError = $e;
                if (!in_array($e->getHttpStatus(), [404, 409], true)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException(
            __('plugins.generic.zenodo.issue.error.publishVerify')
            . ($lastError ? ' ' . $lastError->getMessage() : '')
        );
    }

    private function recordToSavedIssue(array $record, string $environment, array $articles): array
    {
        $id = trim((string) ($record['id'] ?? $record['recid'] ?? ''));
        $doi = $this->extractDoi($record);
        $links = is_array($record['links'] ?? null) ? $record['links'] : [];
        $url = $this->isPublished($record)
            ? ($links['record_html'] ?? $links['self_html'] ?? null)
            : ($links['self_html'] ?? $links['record_html'] ?? null);
        if ((!is_string($url) || $url === '') && $id !== '') {
            $host = $environment === 'production' ? 'https://zenodo.org' : 'https://sandbox.zenodo.org';
            $url = $host . ($this->isPublished($record) ? '/records/' : '/uploads/') . rawurlencode($id);
        }

        return [
            'id' => $id,
            'apiVersion' => self::API_VERSION,
            'status' => $this->isPublished($record) ? 'published' : 'draft',
            'doi' => $doi !== '' ? $doi : null,
            'url' => is_string($url) ? $url : null,
            'environment' => $environment,
            'articleCount' => count($articles),
            'articleDois' => array_values(array_map(static fn(array $a): string => (string) $a['doi'], $articles)),
            'updatedAt' => date('c'),
        ];
    }

    private function isPublished(array $record): bool
    {
        return !empty($record['submitted'])
            || !empty($record['is_published'])
            || in_array(strtolower((string) ($record['status'] ?? $record['state'] ?? '')), ['done', 'published'], true);
    }

    private function extractDoi(array $record): string
    {
        $values = [
            $record['pids']['doi']['identifier'] ?? null,
            $record['doi'] ?? null,
            $record['metadata']['prereserve_doi']['doi'] ?? null,
        ];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return preg_replace('#^https?://(?:dx\\.)?doi\\.org/#i', '', trim((string) $value)) ?: '';
            }
        }
        return '';
    }

    private function getIssueDoi($issue): string
    {
        $doiObject = $issue->getData('doiObject');
        if (!$doiObject && $issue->getData('doiId')) {
            try {
                $doiObject = Repo::doi()->get((int) $issue->getData('doiId'));
            } catch (\Throwable $ignored) {
            }
        }
        if ($doiObject && is_object($doiObject)) {
            $doi = trim((string) $doiObject->getData('doi'));
            return preg_replace('#^https?://(?:dx\\.)?doi\\.org/#i', '', $doi) ?: '';
        }
        return '';
    }

    private function storeDoiInOjsIfAllowed(int $contextId, $issue, string $doi): bool
    {
        if ($this->plugin->getEnvironment($contextId) !== 'production' || !$this->plugin->getStoreReservedDoi($contextId)) {
            return false;
        }

        $doi = preg_replace('#^https?://(?:dx\\.)?doi\\.org/#i', '', trim($doi)) ?: '';
        if ($doi === '') {
            return false;
        }
        $existing = $this->getIssueDoi($issue);
        if ($existing !== '') {
            return strcasecmp($existing, $doi) === 0;
        }

        $doiObject = Repo::doi()->newDataObject([
            'doi' => $doi,
            'contextId' => $contextId,
        ]);
        $doiId = (int) Repo::doi()->add($doiObject);
        if (!$doiId) {
            throw new \RuntimeException(__('plugins.generic.zenodo.issue.error.doiStoreFailed'));
        }
        Repo::issue()->edit($issue, ['doiId' => $doiId]);
        return true;
    }

    private function dateOnly($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
