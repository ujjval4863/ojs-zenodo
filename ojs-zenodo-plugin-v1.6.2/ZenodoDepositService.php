<?php

namespace APP\plugins\generic\zenodo;

use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use PKP\config\Config;
use PKP\submissionFile\SubmissionFile;

class ZenodoDepositService
{
    private const API_VERSION = 'rdm-v1';

    public function __construct(private ZenodoPlugin $plugin)
    {
    }

    public function run(int $contextId, int $submissionId, string $action): array
    {
        return $this->withSubmissionLock($contextId, $submissionId, function () use ($contextId, $submissionId, $action): array {
            $submission = Repo::submission()->get($submissionId);
            if (!$submission || (int) $submission->getData('contextId') !== $contextId) {
                throw new \RuntimeException(__('plugins.generic.zenodo.error.submissionNotFound'));
            }

            $result = match ($action) {
                'process' => $this->depositDraft($contextId, $submission, true),
                'deposit' => $this->depositDraft($contextId, $submission, false),
                'refresh' => $this->refresh($contextId, $submission),
                'publish' => $this->publish($contextId, $submission),
                default => throw new \RuntimeException(__('plugins.generic.zenodo.deposit.actionInvalid')),
            };

            $this->plugin->updateSetting($contextId, 'lastSubmissionId', $submissionId, 'int');
            return $result;
        });
    }

    private function withSubmissionLock(int $contextId, int $submissionId, callable $callback): array
    {
        $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'ojs-zenodo-' . $contextId . '-' . $submissionId . '.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            // Do not make a restrictive host configuration block deposits; the
            // early persisted draft ID below still provides duplicate protection.
            return $callback();
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(__('plugins.generic.zenodo.error.lockFailed'));
            }
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function depositDraft(int $contextId, $submission, bool $forcePublish = false): array
    {
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.noPublication'));
        }

        $pdf = $this->findPdf($publication);
        if (!$pdf) {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.noPdf'));
        }

        $payload = $this->buildRecordPayload($contextId, $submission, $publication);
        $api = $this->plugin->getApi($contextId);
        $environment = $this->plugin->getEnvironment($contextId);
        $saved = $this->plugin->getDeposit($contextId, (int) $submission->getId());
        $record = null;

        // Reuse any saved draft from the same environment when the current
        // Records API can resolve it. This also gives v1.2.x deposits a safe
        // migration path without creating a duplicate when Zenodo exposes the
        // legacy deposit through the current draft endpoint.
        if (
            $saved
            && !empty($saved['id'])
            && ($saved['environment'] ?? $environment) === $environment
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
                        $doi = $this->extractDoi($published);
                        $doiStored = $doi !== ''
                            ? $this->storeReservedDoiInOjsIfAllowed($contextId, $publication, $doi)
                            : false;
                        $updated = $this->recordToSavedDeposit(
                            $published,
                            $environment,
                            (string) ($saved['fileName'] ?? '')
                        );
                        $updated['doiStoredInOjs'] = $doiStored || (bool) ($saved['doiStoredInOjs'] ?? false);
                        $this->plugin->saveDeposit($contextId, (int) $submission->getId(), $updated);
                        return $updated;
                    }
                } catch (ZenodoApiException $recordError) {
                    if ($recordError->getHttpStatus() !== 404) {
                        throw $recordError;
                    }
                }
            }
        }

        if ($record && $this->isPublished($record)) {
            $doi = $this->extractDoi($record);
            $doiStored = $doi !== ''
                ? $this->storeReservedDoiInOjsIfAllowed($contextId, $publication, $doi)
                : false;
            $updated = $this->recordToSavedDeposit(
                $record,
                $environment,
                (string) ($saved['fileName'] ?? '')
            );
            $updated['doiStoredInOjs'] = $doiStored || (bool) ($saved['doiStoredInOjs'] ?? false);
            $this->plugin->saveDeposit($contextId, (int) $submission->getId(), $updated);
            return $updated;
        }

        if (!$record) {
            $record = $api->createDraft($payload);
        } else {
            $record = $api->updateDraft((string) $record['id'], $payload);
        }

        $depositId = trim((string) ($record['id'] ?? $record['recid'] ?? ''));
        if ($depositId === '') {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.invalidResponse'));
        }

        // Persist the Zenodo ID immediately. This closes the duplicate-draft
        // window before PDF upload/DOI reservation and lets a retried request
        // reuse this exact draft instead of creating another one.
        $provisionalState = $this->recordToSavedDeposit($record, $environment);
        $provisionalState['doiStoredInOjs'] = (bool) ($saved['doiStoredInOjs'] ?? false);
        $this->plugin->saveDeposit($contextId, (int) $submission->getId(), $provisionalState);

        $this->uploadAndVerifyPdf($api, $record, $depositId, $pdf);

        // Reserve a DOI before publication so it can be shown in OJS immediately.
        $record = $api->getDraft($depositId);
        $doi = $this->extractDoi($record);
        if ($doi === '') {
            $record = $api->reserveDoi(
                $depositId,
                isset($record['links']['reserve_doi']) && is_string($record['links']['reserve_doi'])
                    ? $record['links']['reserve_doi']
                    : null
            );
            // Some Zenodo responses are minimal; re-fetch the draft for canonical state.
            $record = $api->getDraft($depositId);
            $doi = $this->extractDoi($record);
        }

        if ($doi === '') {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.doiNotReserved'));
        }

        // Keep the reserved DOI in plugin state, but do not write it into OJS
        // until Zenodo has actually published the record. This prevents a failed
        // publish from leaving a DOI on an OJS article that has no public Zenodo record.
        $savedState = $this->recordToSavedDeposit($record, $environment, $pdf['name']);
        $savedState['doiStoredInOjs'] = (bool) ($saved['doiStoredInOjs'] ?? false);
        $this->plugin->saveDeposit($contextId, (int) $submission->getId(), $savedState);

        if ($forcePublish) {
            // Publish the exact draft created/updated above. Do not re-enter the
            // generic publish path or re-resolve/create a record.
            return $this->publishResolvedDraft($contextId, $submission, $api, $record, $savedState);
        }

        return $savedState;
    }

    private function refresh(int $contextId, $submission): array
    {
        $saved = $this->requireSavedDeposit($contextId, (int) $submission->getId());
        $this->assertEnvironmentMatches($contextId, $saved);
        $this->assertCurrentApiVersion($saved);

        $api = $this->plugin->getApi($contextId);
        $id = (string) $saved['id'];
        try {
            $record = $api->getDraft($id);
        } catch (ZenodoApiException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }
            $record = $api->getRecord($id);
        }

        $updated = $this->recordToSavedDeposit(
            $record,
            $this->plugin->getEnvironment($contextId),
            (string) ($saved['fileName'] ?? '')
        );
        $updated['doiStoredInOjs'] = (bool) ($saved['doiStoredInOjs'] ?? false);
        $this->plugin->saveDeposit($contextId, (int) $submission->getId(), $updated);
        return $updated;
    }

    private function publish(int $contextId, $submission): array
    {
        $saved = $this->requireSavedDeposit($contextId, (int) $submission->getId());
        $this->assertEnvironmentMatches($contextId, $saved);
        $this->assertCurrentApiVersion($saved);

        $api = $this->plugin->getApi($contextId);
        $id = (string) $saved['id'];

        try {
            $current = $api->getDraft($id);
        } catch (ZenodoApiException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }
            $current = $api->getRecord($id);
        }

        return $this->publishResolvedDraft($contextId, $submission, $api, $current, $saved);
    }

    private function publishResolvedDraft(
        int $contextId,
        $submission,
        ZenodoApi $api,
        array $current,
        array $saved
    ): array {
        $id = trim((string) ($saved['id'] ?? $current['id'] ?? $current['recid'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.invalidResponse'));
        }

        if ($this->isPublished($current)) {
            $publication = $submission->getCurrentPublication();
            $doi = $this->extractDoi($current);
            $doiStored = $publication && $doi !== ''
                ? $this->storeReservedDoiInOjsIfAllowed($contextId, $publication, $doi)
                : false;
            $updated = $this->recordToSavedDeposit(
                $current,
                $this->plugin->getEnvironment($contextId),
                (string) ($saved['fileName'] ?? '')
            );
            $updated['doiStoredInOjs'] = $doiStored || (bool) ($saved['doiStoredInOjs'] ?? false);
            $this->plugin->saveDeposit($contextId, (int) $submission->getId(), $updated);
            return $updated;
        }

        $fileName = trim((string) ($saved['fileName'] ?? ''));
        if ($fileName !== '') {
            $this->waitForDraftFileReady($api, $id, $fileName);
            // Re-fetch after file processing so publish uses the latest draft links/state.
            $current = $api->getDraft($id);
        }

        // InvenioRDM may expose validation errors directly on the draft
        // before publish. Surface them instead of making a doomed publish call.
        $preflightErrors = $api->getValidationErrorDetails($current);
        if ($preflightErrors) {
            throw new \RuntimeException(
                'Zenodo draft validation failed: ' . implode('; ', $preflightErrors)
            );
        }

        try {
            $publishResponse = $api->publishDraft(
                $id,
                isset($current['links']['publish']) && is_string($current['links']['publish'])
                    ? $current['links']['publish']
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

            // Some Zenodo deployments return only the generic HTTP 400 message
            // from the publish action but persist field-level errors on the
            // draft. Re-fetch once and attach those errors to the OJS job status.
            if ($e->getHttpStatus() === 400) {
                try {
                    $failedDraft = $api->getDraft($id);
                    $draftErrors = $api->getValidationErrorDetails($failedDraft);
                    if ($draftErrors) {
                        throw new \RuntimeException(
                            $e->getMessage() . ' — ' . implode('; ', $draftErrors),
                            400,
                            $e
                        );
                    }
                } catch (\RuntimeException $detailError) {
                    if ($detailError->getPrevious() === $e) {
                        throw $detailError;
                    }
                    // Preserve the original Zenodo publish error if the
                    // diagnostic re-fetch itself fails.
                }
            }
            throw $e;
        }

        // Canonicalize the result from the public record endpoint. Some
        // deployments return only a minimal body to the publish POST.
        $record = $this->fetchPublishedRecord($api, $id, $publishResponse);

        $publication = $submission->getCurrentPublication();
        $doi = $this->extractDoi($record);
        $doiStored = $publication && $doi !== ''
            ? $this->storeReservedDoiInOjsIfAllowed($contextId, $publication, $doi)
            : false;

        $updated = $this->recordToSavedDeposit(
            $record,
            $this->plugin->getEnvironment($contextId),
            (string) ($saved['fileName'] ?? '')
        );
        $updated['doiStoredInOjs'] = $doiStored || (bool) ($saved['doiStoredInOjs'] ?? false);
        $this->plugin->saveDeposit($contextId, (int) $submission->getId(), $updated);
        return $updated;
    }

    private function fetchPublishedRecord(ZenodoApi $api, string $id, array $publishResponse): array
    {
        if ($this->isPublished($publishResponse) && trim((string) ($publishResponse['id'] ?? '')) !== '') {
            return $publishResponse;
        }

        $lastError = null;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($attempt > 0) {
                usleep(350000);
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

        $suffix = $lastError ? ' ' . $lastError->getMessage() : '';
        throw new \RuntimeException(__('plugins.generic.zenodo.error.publishVerify') . $suffix);
    }

    private function uploadAndVerifyPdf(ZenodoApi $api, array $record, string $id, array $pdf): void
    {
        $filename = $pdf['name'];

        // Remove the same filename before replacing it, but keep any other files
        // that an editor may have added manually in Zenodo.
        $existing = $this->fileEntries($api->listDraftFiles(
            $id,
            isset($record['links']['files']) && is_string($record['links']['files'])
                ? $record['links']['files']
                : null
        ));
        foreach ($existing as $entry) {
            if ((string) ($entry['key'] ?? '') === $filename) {
                try {
                    $api->deleteDraftFile($id, $filename);
                } catch (ZenodoApiException $e) {
                    if ($e->getHttpStatus() !== 404) {
                        throw $e;
                    }
                }
                break;
            }
        }

        $stagedError = null;
        try {
            $initialized = $api->initializeDraftFile(
                $id,
                $filename,
                isset($record['links']['files']) && is_string($record['links']['files'])
                    ? $record['links']['files']
                    : null
            );
            $contentUrl = $initialized['links']['content'] ?? null;
            $commitUrl = $initialized['links']['commit'] ?? null;
            if (!is_string($contentUrl) || $contentUrl === '' || !is_string($commitUrl) || $commitUrl === '') {
                throw new \RuntimeException(__('plugins.generic.zenodo.error.fileLinksMissing'));
            }

            $api->uploadDraftFileContent($contentUrl, $pdf['path']);
            $api->commitDraftFile($commitUrl);

            if ($this->draftHasUploadedFile($api, $id, $filename)) {
                return;
            }
            $stagedError = new \RuntimeException(__('plugins.generic.zenodo.error.fileUploadVerify'));
        } catch (\Throwable $e) {
            $stagedError = $e;
        }

        // Zenodo has been transitioning its file backend. If the staged commit
        // path fails, use the documented compatibility bucket endpoint and then
        // verify that Zenodo actually reports a non-empty file.
        try {
            try {
                $api->deleteDraftFile($id, $filename);
            } catch (\Throwable $ignored) {
                // Best-effort cleanup of a pending staged entry.
            }

            $legacy = $api->getLegacyDeposition($id);
            $bucketUrl = $legacy['links']['bucket'] ?? null;
            if (!is_string($bucketUrl) || $bucketUrl === '') {
                throw new \RuntimeException(__('plugins.generic.zenodo.error.noBucket'));
            }
            $api->uploadToLegacyBucket($bucketUrl, $pdf['path'], $filename);

            if ($this->draftHasUploadedFile($api, $id, $filename)) {
                return;
            }
        } catch (\Throwable $fallbackError) {
            $message = $stagedError ? $stagedError->getMessage() . ' ' : '';
            $message .= $fallbackError->getMessage();
            throw new \RuntimeException(__('plugins.generic.zenodo.error.fileUploadFailed') . ' ' . trim($message));
        }

        throw new \RuntimeException(__('plugins.generic.zenodo.error.fileUploadVerify'));
    }

    private function draftHasUploadedFile(ZenodoApi $api, string $id, string $filename): bool
    {
        $entries = $this->fileEntries($api->listDraftFiles($id));
        foreach ($entries as $entry) {
            if ((string) ($entry['key'] ?? '') !== $filename) {
                continue;
            }
            $size = (int) ($entry['size'] ?? 0);
            $status = strtolower((string) ($entry['status'] ?? ''));
            if ($size > 0 && !in_array($status, ['pending', 'failed'], true)) {
                return true;
            }
        }
        return false;
    }


    /**
     * Zenodo can accept a committed file before all asynchronous file metadata
     * (checksum/status) has settled. Publishing during that window can return
     * HTTP 400. Wait for the uploaded PDF to be publish-ready before invoking
     * the publish action.
     */
    private function waitForDraftFileReady(ZenodoApi $api, string $id, string $filename): void
    {
        $lastStatus = '';
        for ($attempt = 0; $attempt < 24; $attempt++) {
            if ($attempt > 0) {
                sleep(5);
            }

            $entries = $this->fileEntries($api->listDraftFiles($id));
            foreach ($entries as $entry) {
                if ((string) ($entry['key'] ?? '') !== $filename) {
                    continue;
                }

                $size = (int) ($entry['size'] ?? 0);
                $status = strtolower(trim((string) ($entry['status'] ?? '')));
                $checksum = trim((string) ($entry['checksum'] ?? ''));
                $lastStatus = $status !== '' ? $status : 'unknown';

                if ($status === 'failed') {
                    throw new \RuntimeException('Zenodo reports that the uploaded PDF failed file processing.');
                }

                // Current InvenioRDM normally reports completed when the file
                // is ready. Some deployments omit status but expose checksum;
                // in that case a non-empty checksum plus a non-zero size is a
                // reliable readiness signal.
                if (
                    $size > 0
                    && (
                        in_array($status, ['completed', 'done', 'ready'], true)
                        || ($status === '' && $checksum !== '')
                        || ($checksum !== '' && $status !== 'pending')
                    )
                ) {
                    return;
                }
            }
        }

        throw new \RuntimeException(
            'Zenodo PDF upload is present but is not yet ready for publication'
            . ($lastStatus !== '' ? ' (file status: ' . $lastStatus . ')' : '')
            . '. Please retry the queued action after Zenodo finishes file processing.'
        );
    }

    private function fileEntries(array $response): array
    {
        if (isset($response['entries']) && is_array($response['entries'])) {
            return $response['entries'];
        }
        // Legacy-compatible responses can be a plain list.
        return array_is_list($response) ? $response : [];
    }

    private function requireSavedDeposit(int $contextId, int $submissionId): array
    {
        $saved = $this->plugin->getDeposit($contextId, $submissionId);
        if (!$saved || empty($saved['id'])) {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.noDeposit'));
        }
        return $saved;
    }

    private function assertCurrentApiVersion(array $saved): void
    {
        if (($saved['apiVersion'] ?? '') !== self::API_VERSION) {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.legacyDeposit'));
        }
    }

    private function assertEnvironmentMatches(int $contextId, array $saved): void
    {
        $current = $this->plugin->getEnvironment($contextId);
        $savedEnvironment = (string) ($saved['environment'] ?? $current);
        if ($savedEnvironment !== $current) {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.environmentChanged'));
        }
    }

    private function isPublished(array $record): bool
    {
        return !empty($record['submitted'])
            || !empty($record['is_published'])
            || in_array(strtolower((string) ($record['status'] ?? $record['state'] ?? '')), ['done', 'published'], true);
    }

    private function recordToSavedDeposit(array $record, string $environment, string $fileName = ''): array
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
            'submitted' => (bool) ($record['submitted'] ?? false),
            'doi' => $doi !== '' ? $doi : null,
            'url' => is_string($url) ? $url : null,
            'environment' => $environment,
            'fileName' => $fileName !== '' ? $fileName : null,
            'updatedAt' => date('c'),
        ];
    }

    private function extractDoi(array $record): string
    {
        $candidates = [
            $record['pids']['doi']['identifier'] ?? null,
            $record['doi'] ?? null,
            $record['metadata']['prereserve_doi']['doi'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', trim((string) $candidate)) ?: '';
            }
        }
        return '';
    }

    private function storeReservedDoiInOjsIfAllowed(int $contextId, $publication, string $doi): bool
    {
        if ($this->plugin->getEnvironment($contextId) !== 'production') {
            return false;
        }
        if (!$this->plugin->getStoreReservedDoi($contextId)) {
            return false;
        }

        $existing = '';
        if (method_exists($publication, 'getDoi')) {
            $existing = trim((string) $publication->getDoi());
        }
        if ($existing === '' && method_exists($publication, 'getStoredPubId')) {
            $existing = trim((string) $publication->getStoredPubId('doi'));
        }
        if ($existing !== '') {
            return false;
        }

        if (!method_exists($publication, 'setStoredPubId')) {
            throw new \RuntimeException('This OJS publication object cannot store DOI metadata.');
        }

        // OJS 3.5 stores publication DOIs as DOI objects referenced by doiId.
        // setStoredPubId() creates/updates that DOI object and sets doiId on
        // the in-memory publication; edit() persists the foreign key.
        $publication->setStoredPubId('doi', $doi);
        $doiId = (int) $publication->getData('doiId');
        if (!$doiId) {
            throw new \RuntimeException('OJS did not create a DOI record for the reserved Zenodo DOI.');
        }
        Repo::publication()->edit($publication, ['doiId' => $doiId]);
        return true;
    }

    private function buildRecordPayload(int $contextId, $submission, $publication): array
    {
        $context = Application::getContextDAO()->getById($contextId);

        $title = method_exists($publication, 'getLocalizedFullTitle')
            ? trim((string) $publication->getLocalizedFullTitle())
            : trim((string) $publication->getLocalizedData('title'));
        if ($title === '') {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.noTitle'));
        }

        $creators = $this->getCreators($publication);
        if (!$creators) {
            throw new \RuntimeException(__('plugins.generic.zenodo.error.noAuthors'));
        }

        $abstract = trim((string) $publication->getLocalizedData('abstract'));
        $metadata = [
            'resource_type' => ['id' => 'publication-article'],
            'title' => $title,
            'publication_date' => $this->normalizeDate(
                $publication->getData('datePublished') ?: $submission->getData('dateSubmitted')
            ),
            'creators' => $creators,
            'description' => $abstract !== '' ? $abstract : $title,
            'publisher' => $this->plugin->getPublisher($contextId),
            'version' => $this->plugin->getResourceVersion($contextId),
            'languages' => [['id' => $this->plugin->getLanguageCode($contextId)]],
        ];

        $rights = $this->getRights($publication);
        if ($rights) {
            $metadata['rights'] = $rights;
        }
        $copyright = $this->getCopyright($publication);
        if ($copyright !== '') {
            $metadata['copyright'] = $copyright;
        }

        $references = $this->getReferences($publication);
        if ($references) {
            $metadata['references'] = array_map(
                static fn(string $reference): array => ['reference' => $reference],
                $references
            );
        }

        $dates = $this->getWorkflowDates($submission, $publication);
        if ($dates) {
            $metadata['dates'] = $dates;
        }

        $keywords = $this->getKeywords($publication);
        if ($keywords) {
            $metadata['subjects'] = array_map(
                static fn(string $keyword): array => ['subject' => $keyword],
                $keywords
            );
        }

        $journal = [];
        if ($context && (int) $context->getId() === $contextId) {
            $journalTitle = method_exists($context, 'getLocalizedName')
                ? trim((string) $context->getLocalizedName())
                : trim((string) $context->getLocalizedData('name'));
            if ($journalTitle !== '') {
                $journal['title'] = $journalTitle;
            }
        }
        $issn = $this->plugin->getJournalIssn($contextId);
        if ($issn !== '') {
            $journal['issn'] = $issn;
        }

        $issueId = (int) $publication->getData('issueId');
        if ($issueId) {
            $issue = Repo::issue()->get($issueId);
            if ($issue) {
                $volume = trim((string) $issue->getData('volume'));
                $number = trim((string) $issue->getData('number'));
                if ($volume !== '') {
                    $journal['volume'] = $volume;
                }
                if ($number !== '') {
                    $journal['issue'] = $number;
                }
            }
        }
        $pages = trim((string) $publication->getData('pages'));
        if ($pages !== '') {
            $journal['pages'] = $pages;
        }

        $payload = [
            'access' => ['record' => 'public', 'files' => 'public'],
            'files' => ['enabled' => true],
            'metadata' => $metadata,
        ];

        // If OJS already assigned a DOI (for example through another DOI
        // registration agency), tell Zenodo to use it as an external DOI.
        // Never register a second DOI for the same article.
        $existingDoi = '';
        if (method_exists($publication, 'getDoi')) {
            $existingDoi = trim((string) $publication->getDoi());
        }
        if ($existingDoi === '' && method_exists($publication, 'getStoredPubId')) {
            $existingDoi = trim((string) $publication->getStoredPubId('doi'));
        }
        if ($existingDoi !== '') {
            $existingDoi = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', $existingDoi) ?: '';
            if ($existingDoi !== '') {
                $payload['pids'] = [
                    'doi' => [
                        'identifier' => $existingDoi,
                        'provider' => 'external',
                    ],
                ];
            }
        }
        if ($journal) {
            $payload['custom_fields'] = ['journal:journal' => $journal];
        }

        return $payload;
    }

    private function getRights($publication): array
    {
        $url = strtolower(trim((string) $publication->getData('licenseUrl')));
        if ($url === '') {
            return [];
        }

        $map = [
            '/licenses/by/4.0' => 'cc-by-4.0',
            '/licenses/by-sa/4.0' => 'cc-by-sa-4.0',
            '/licenses/by-nd/4.0' => 'cc-by-nd-4.0',
            '/licenses/by-nc/4.0' => 'cc-by-nc-4.0',
            '/licenses/by-nc-sa/4.0' => 'cc-by-nc-sa-4.0',
            '/licenses/by-nc-nd/4.0' => 'cc-by-nc-nd-4.0',
            '/publicdomain/zero/1.0' => 'cc0-1.0',
        ];
        foreach ($map as $fragment => $id) {
            if (str_contains($url, $fragment)) {
                return [['id' => $id]];
            }
        }
        return [];
    }

    private function getCopyright($publication): string
    {
        $holder = trim((string) $publication->getLocalizedData('copyrightHolder'));
        $year = trim((string) $publication->getData('copyrightYear'));
        if ($holder === '' && $year === '') {
            return '';
        }
        if ($holder === '') {
            $holder = 'The Authors';
        }
        $parts = ['Copyright (C)'];
        if ($year !== '') {
            $parts[] = $year;
        }
        $parts[] = rtrim($holder, '.');
        return implode(' ', $parts) . '.';
    }

    private function getCreators($publication): array
    {
        $creators = [];
        $authors = $publication->getData('authors');
        if (!$authors || !is_iterable($authors)) {
            return [];
        }

        foreach ($authors as $author) {
            if (!is_object($author)) {
                continue;
            }

            $family = method_exists($author, 'getLocalizedFamilyName')
                ? trim((string) $author->getLocalizedFamilyName())
                : trim((string) $author->getLocalizedData('familyName'));
            $given = method_exists($author, 'getLocalizedGivenName')
                ? trim((string) $author->getLocalizedGivenName())
                : trim((string) $author->getLocalizedData('givenName'));
            $name = trim($family . ($family !== '' && $given !== '' ? ', ' : '') . $given);
            $fullName = method_exists($author, 'getFullName')
                ? trim((string) $author->getFullName())
                : '';
            if ($name === '') {
                $name = $fullName;
            } elseif ($family === '' && $fullName !== '') {
                // When OJS has no structured familyName, prefer its full
                // display name as the source for deriving Zenodo name parts.
                $name = $fullName;
            }
            if ($name === '') {
                continue;
            }

            // Zenodo/InvenioRDM requires family_name for every personal
            // creator. OJS can contain migrated/legacy author records where
            // familyName is empty even though a usable display name exists.
            // Derive a deterministic fallback from the display name instead
            // of sending a blank family_name (which blocks publication).
            if ($family === '') {
                [$derivedGiven, $derivedFamily] = $this->splitCreatorName($name);
                $family = $derivedFamily;
                // Once we have to derive a missing family name, derive the
                // given name from the same display string too. This avoids
                // sending a mononym as both given_name and family_name.
                $given = $derivedGiven;
            }

            if ($family === '') {
                // A final guard for unusual single-token/whitespace names.
                // Zenodo accepts a mononym when it is supplied as family_name.
                $family = $name;
                $given = '';
            }

            $person = [
                'type' => 'personal',
                'name' => $name,
                'family_name' => $family,
            ];
            if ($given !== '') {
                $person['given_name'] = $given;
            }

            $orcid = method_exists($author, 'getOrcid')
                ? trim((string) $author->getOrcid())
                : trim((string) $author->getData('orcid'));
            $orcid = preg_replace('#^https?://orcid\.org/#i', '', $orcid) ?: '';
            if ($orcid !== '' && $this->isValidOrcid($orcid)) {
                $person['identifiers'] = [[
                    'scheme' => 'orcid',
                    'identifier' => strtoupper($orcid),
                ]];
            }

            $creator = ['person_or_org' => $person];
            $affiliations = [];
            if (method_exists($author, 'getAffiliations')) {
                foreach ($author->getAffiliations() as $affiliation) {
                    if (!is_object($affiliation)) {
                        continue;
                    }
                    $affiliationName = method_exists($affiliation, 'getLocalizedName')
                        ? trim((string) $affiliation->getLocalizedName())
                        : '';
                    if ($affiliationName === '') {
                        continue;
                    }
                    $entry = ['name' => $affiliationName];
                    if (method_exists($affiliation, 'getRor')) {
                        $ror = trim((string) $affiliation->getRor());
                        $ror = preg_replace('#^https?://ror\.org/#i', '', $ror) ?: '';
                        if ($ror !== '') {
                            $entry['identifiers'] = [[
                                'scheme' => 'ror',
                                'identifier' => $ror,
                            ]];
                        }
                    }
                    $affiliations[] = $entry;
                }
            }
            // Authors migrated from older OJS versions may still expose a
            // localized free-text affiliation even when no structured 3.5
            // affiliation objects are attached. Preserve it as a fallback.
            if (!$affiliations) {
                $legacyAffiliation = '';
                if (method_exists($author, 'getLocalizedAffiliation')) {
                    $legacyAffiliation = trim((string) $author->getLocalizedAffiliation());
                }
                if ($legacyAffiliation === '' && method_exists($author, 'getLocalizedData')) {
                    $legacyAffiliation = trim((string) $author->getLocalizedData('affiliation'));
                }
                if ($legacyAffiliation !== '') {
                    $affiliations[] = ['name' => $legacyAffiliation];
                }
            }
            if ($affiliations) {
                $creator['affiliations'] = $affiliations;
            }

            $creators[] = $creator;
        }

        return $creators;
    }


    /**
     * Split an OJS display name into Zenodo-compatible given/family parts.
     *
     * OJS legacy data can have a blank familyName while getFullName() still
     * contains the creator's name. Prefer an explicit "Family, Given" form;
     * otherwise treat the final whitespace-delimited token as the family name.
     * A single-token name is treated as a mononym/family name.
     *
     * @return array{0:string,1:string} [givenName, familyName]
     */
    private function splitCreatorName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return ['', ''];
        }

        if (str_contains($name, ',')) {
            [$family, $given] = array_pad(array_map('trim', explode(',', $name, 2)), 2, '');
            if ($family !== '') {
                return [$given, $family];
            }
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        if (!$parts) {
            return ['', ''];
        }
        if (count($parts) === 1) {
            return ['', $parts[0]];
        }

        $family = array_pop($parts);
        return [implode(' ', $parts), (string) $family];
    }

    /**
     * Validate an ORCID using the ISO 7064 MOD 11-2 checksum used by ORCID.
     * Zenodo/InvenioRDM validates ORCID identifiers during record processing,
     * so a merely well-formatted but checksum-invalid value must not be sent.
     */
    private function isValidOrcid(string $orcid): bool
    {
        $orcid = strtoupper(trim($orcid));
        if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) {
            return false;
        }

        $compact = str_replace('-', '', $orcid);
        $total = 0;
        for ($i = 0; $i < 15; $i++) {
            $total = ($total + (int) $compact[$i]) * 2;
        }
        $remainder = $total % 11;
        $result = (12 - $remainder) % 11;
        $check = $result === 10 ? 'X' : (string) $result;

        return $compact[15] === $check;
    }

    private function getReferences($publication): array
    {
        $references = [];
        $citations = $publication->getData('citations') ?? [];
        if (!is_iterable($citations)) {
            return [];
        }
        foreach ($citations as $citation) {
            if (!is_object($citation) || !method_exists($citation, 'getRawCitation')) {
                continue;
            }
            $raw = trim((string) $citation->getRawCitation());
            if ($raw !== '') {
                $references[] = $raw;
            }
        }
        return array_values(array_unique($references));
    }

    private function getWorkflowDates($submission, $publication): array
    {
        $dates = [];
        $submitted = $this->dateOnly($submission->getData('dateSubmitted'));
        if ($submitted) {
            $dates[] = [
                'date' => $submitted,
                'type' => ['id' => 'submitted'],
                'description' => 'Submitted to the journal',
            ];
        }

        $reviewed = null;
        try {
            $assignments = Repo::reviewAssignment()->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->getMany();
            foreach ($assignments as $assignment) {
                if (!is_object($assignment) || !method_exists($assignment, 'getDateCompleted')) {
                    continue;
                }
                $date = $this->dateOnly($assignment->getDateCompleted());
                if ($date && (!$reviewed || $date > $reviewed)) {
                    $reviewed = $date;
                }
            }
        } catch (\Throwable $ignored) {
        }
        if ($reviewed) {
            $dates[] = [
                'date' => $reviewed,
                'type' => ['id' => 'other'],
                'description' => 'Peer review completed',
            ];
        }

        $accepted = null;
        try {
            $decisions = Repo::decision()->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->getMany();
            foreach ($decisions as $decision) {
                if ((int) $decision->getData('decision') !== (int) Decision::ACCEPT) {
                    continue;
                }
                $date = $this->dateOnly($decision->getData('dateDecided'));
                if ($date && (!$accepted || $date > $accepted)) {
                    $accepted = $date;
                }
            }
        } catch (\Throwable $ignored) {
        }
        if ($accepted) {
            $dates[] = [
                'date' => $accepted,
                'type' => ['id' => 'accepted'],
                'description' => 'Accepted and entered copyediting',
            ];
        }

        $production = null;
        try {
            $decisions = Repo::decision()->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->getMany();
            foreach ($decisions as $decision) {
                if ((int) $decision->getData('decision') !== (int) Decision::SEND_TO_PRODUCTION) {
                    continue;
                }
                $date = $this->dateOnly($decision->getData('dateDecided'));
                if ($date && (!$production || $date > $production)) {
                    $production = $date;
                }
            }
        } catch (\Throwable $ignored) {
        }

        // Fallback for migrated or incomplete workflow histories where the
        // Send-to-Production decision is unavailable.
        if (!$production) {
            try {
                $files = Repo::submissionFile()->getCollector()
                    ->filterBySubmissionIds([(int) $submission->getId()])
                    ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
                    ->getMany();
                foreach ($files as $file) {
                    $date = $this->dateOnly($file->getData('createdAt'));
                    if ($date && (!$production || $date < $production)) {
                        $production = $date;
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }
        if ($production) {
            $dates[] = [
                'date' => $production,
                'type' => ['id' => 'other'],
                'description' => 'Entered production',
            ];
        }

        $published = $this->dateOnly($publication->getData('datePublished'));
        if ($published) {
            $dates[] = [
                'date' => $published,
                'type' => ['id' => 'issued'],
                'description' => 'Published',
            ];
        }

        return $dates;
    }

    private function getKeywords($publication): array
    {
        $result = [];
        $append = static function ($value) use (&$result): void {
            if (is_scalar($value)) {
                foreach (preg_split('/[,;\n]+/', (string) $value) ?: [] as $piece) {
                    $piece = trim($piece);
                    if ($piece !== '') {
                        $result[] = $piece;
                    }
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item) && trim((string) $item) !== '') {
                        $result[] = trim((string) $item);
                    } elseif (is_array($item) && isset($item['name']) && is_scalar($item['name'])) {
                        $name = trim((string) $item['name']);
                        if ($name !== '') {
                            $result[] = $name;
                        }
                    }
                }
            }
        };

        $append($publication->getLocalizedData('keywords'));
        if (!$result) {
            $raw = $publication->getData('keywords');
            if (is_iterable($raw)) {
                foreach ($raw as $localeItems) {
                    $append($localeItems);
                }
            }
        }
        return array_values(array_unique($result));
    }

    private function normalizeDate($value): string
    {
        return $this->dateOnly($value) ?: date('Y-m-d');
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

    private function findPdf($publication): ?array
    {
        $galleys = $publication->getData('galleys');
        if (!$galleys || !is_iterable($galleys)) {
            return null;
        }

        $fileService = app()->get('file');
        foreach ($galleys as $galley) {
            if (!is_object($galley) || $galley->getData('urlRemote')) {
                continue;
            }

            $submissionFileId = (int) $galley->getData('submissionFileId');
            if (!$submissionFileId) {
                continue;
            }

            $submissionFile = Repo::submissionFile()->get($submissionFileId);
            if (!$submissionFile) {
                continue;
            }

            $mimetype = trim((string) $submissionFile->getData('mimetype'));
            $galleyType = method_exists($galley, 'getFileType') ? trim((string) $galley->getFileType()) : '';
            if ($mimetype !== 'application/pdf' && $galleyType !== 'application/pdf') {
                continue;
            }

            $fileId = (int) $submissionFile->getData('fileId');
            if (!$fileId) {
                continue;
            }

            $fileRecord = $fileService->get($fileId);
            if (!$fileRecord || empty($fileRecord->path)) {
                continue;
            }

            $fullPath = rtrim((string) Config::getVar('files', 'files_dir'), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . ltrim((string) $fileRecord->path, DIRECTORY_SEPARATOR);
            if (!is_file($fullPath) || !is_readable($fullPath) || filesize($fullPath) <= 0) {
                continue;
            }

            $name = $submissionFile->getLocalizedData('name');
            if (!is_scalar($name) || trim((string) $name) === '') {
                $name = 'article-' . (int) $publication->getData('submissionId') . '.pdf';
            }
            $name = basename(trim((string) $name));
            if (!preg_match('/\.pdf$/i', $name)) {
                $name .= '.pdf';
            }

            return [
                'path' => $fullPath,
                'name' => $name,
                'size' => (int) filesize($fullPath),
            ];
        }

        return null;
    }
}
