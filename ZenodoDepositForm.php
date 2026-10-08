<?php

namespace APP\plugins\generic\zenodo;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class ZenodoDepositForm extends Form
{
    public function __construct(
        private ZenodoPlugin $plugin,
        private int $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('deposit.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorRegExp(
            $this,
            'submissionId',
            'required',
            'plugins.generic.zenodo.deposit.submissionIdInvalid',
            '/^[1-9][0-9]*$/'
        ));
    }

    public function initData(): void
    {
        $this->setData([
            'submissionId' => (int) $this->plugin->getSetting($this->contextId, 'lastSubmissionId'),
        ]);
        parent::initData();
    }

    public function readInputData(): void
    {
        $this->readUserVars(['submissionId']);
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $submissionId = (int) $this->getData('submissionId');
        $deposit = $submissionId > 0
            ? $this->plugin->getDeposit($this->contextId, $submissionId)
            : null;
        $jobStatus = $submissionId > 0
            ? $this->plugin->getJobStatus($this->contextId, $submissionId)
            : null;

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'environmentLabel' => $this->plugin->getEnvironment($this->contextId) === 'production'
                ? __('plugins.generic.zenodo.settings.environment.production')
                : __('plugins.generic.zenodo.settings.environment.sandbox'),
            'hasApiToken' => $this->plugin->hasApiToken($this->contextId),
            'depositStatus' => $deposit,
            'jobStatus' => $jobStatus,
        ]);
        return parent::fetch($request, $template, $display);
    }
}
