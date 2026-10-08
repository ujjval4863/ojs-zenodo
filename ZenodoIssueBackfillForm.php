<?php

namespace APP\plugins\generic\zenodo;

use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorInSet;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class ZenodoIssueBackfillForm extends Form
{
    public function __construct(
        private ZenodoPlugin $plugin,
        private int $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('issueBackfill.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorInSet(
            $this,
            'backfillMode',
            'required',
            'plugins.generic.zenodo.backfill.modeInvalid',
            ['one', 'all']
        ));
        $this->addCheck(new FormValidatorRegExp(
            $this,
            'issueId',
            'optional',
            'plugins.generic.zenodo.backfill.issueInvalid',
            '/^[1-9][0-9]*$/'
        ));
    }

    public function initData(): void
    {
        $this->setData([
            'backfillMode' => 'one',
            'issueId' => (int) $this->plugin->getSetting($this->contextId, 'lastBackfillIssueId'),
        ]);
        parent::initData();
    }

    public function readInputData(): void
    {
        $this->readUserVars(['backfillMode', 'issueId']);
    }

    public function validate($callHooks = true)
    {
        parent::validate($callHooks);

        if ((string) $this->getData('backfillMode') === 'one') {
            $issueId = (int) $this->getData('issueId');
            if ($issueId < 1) {
                $this->addError('issueId', __('plugins.generic.zenodo.backfill.issueRequired'));
                $this->addErrorField('issueId');
            } else {
                $issue = Repo::issue()->get($issueId);
                $journalId = $issue
                    ? (method_exists($issue, 'getJournalId') ? (int) $issue->getJournalId() : (int) $issue->getData('journalId'))
                    : 0;
                if (!$issue || $journalId !== $this->contextId || !(bool) $issue->getData('published')) {
                    $this->addError('issueId', __('plugins.generic.zenodo.backfill.issueInvalid'));
                    $this->addErrorField('issueId');
                }
            }
        }

        return $this->isValid();
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $issues = [];
        $published = Repo::issue()->getCollector()
            ->filterByContextIds([$this->contextId])
            ->filterByPublished(true)
            ->getMany();

        foreach ($published as $issue) {
            $issueId = (int) $issue->getId();
            $identification = method_exists($issue, 'getIssueIdentification')
                ? trim((string) $issue->getIssueIdentification())
                : '';
            if ($identification === '') {
                $identification = __('plugins.generic.zenodo.backfill.issueFallback', ['id' => (string) $issueId]);
            }

            $datePublished = trim((string) $issue->getData('datePublished'));
            $date = $datePublished !== '' && strtotime($datePublished) !== false
                ? date('Y-m-d', strtotime($datePublished))
                : '';
            $saved = $this->plugin->getIssueDeposit($this->contextId, $issueId);
            $doi = trim((string) ($saved['doi'] ?? ''));
            $isPublished = strtolower((string) ($saved['status'] ?? '')) === 'published' && $doi !== '';

            $label = $identification;
            if ($date !== '') {
                $label .= ' — ' . $date;
            }
            $label .= $isPublished
                ? ' — ' . __('plugins.generic.zenodo.backfill.alreadyBackfilled', ['doi' => $doi])
                : ' — ' . __('plugins.generic.zenodo.backfill.notBackfilled');

            $issues[] = [
                'id' => $issueId,
                'label' => $label,
                'timestamp' => $datePublished !== '' && strtotime($datePublished) !== false ? strtotime($datePublished) : 0,
                'backfilled' => $isPublished,
            ];
        }

        usort($issues, static function (array $a, array $b): int {
            if ($a['timestamp'] === $b['timestamp']) {
                return $b['id'] <=> $a['id'];
            }
            return $b['timestamp'] <=> $a['timestamp'];
        });

        $issueOptions = [];
        $backfilledCount = 0;
        foreach ($issues as $row) {
            $issueOptions[(string) $row['id']] = $row['label'];
            if ($row['backfilled']) {
                $backfilledCount++;
            }
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'environmentLabel' => $this->plugin->getEnvironment($this->contextId) === 'production'
                ? __('plugins.generic.zenodo.settings.environment.production')
                : __('plugins.generic.zenodo.settings.environment.sandbox'),
            'hasApiToken' => $this->plugin->hasApiToken($this->contextId),
            'issueOptions' => $issueOptions,
            'publishedIssueCount' => count($issues),
            'backfilledIssueCount' => $backfilledCount,
            'pendingIssueCount' => count($issues) - $backfilledCount,
            'backfillStatus' => $this->plugin->getIssueBackfillStatus($this->contextId),
        ]);

        return parent::fetch($request, $template, $display);
    }
}
