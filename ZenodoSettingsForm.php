<?php

namespace APP\plugins\generic\zenodo;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorInSet;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class ZenodoSettingsForm extends Form
{
    public function __construct(
        private ZenodoPlugin $plugin,
        private int $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorInSet(
            $this,
            'environment',
            'required',
            'plugins.generic.zenodo.settings.environmentInvalid',
            ['sandbox', 'production']
        ));
        $this->addCheck(new FormValidatorRegExp(
            $this,
            'journalIssn',
            'optional',
            'plugins.generic.zenodo.settings.issnInvalid',
            '/^\d{4}-\d{3}[\dXx]$/'
        ));
        $this->addCheck(new FormValidatorRegExp(
            $this,
            'languageCode',
            'required',
            'plugins.generic.zenodo.settings.languageInvalid',
            '/^[a-z]{3}$/'
        ));
    }

    public function initData(): void
    {
        $this->setData([
            'environment' => $this->plugin->getEnvironment($this->contextId),
            // Never render a stored access token back into HTML.
            'apiToken' => '',
            'clearApiToken' => false,
            'publisher' => $this->plugin->getPublisher($this->contextId),
            'journalIssn' => $this->plugin->getJournalIssn($this->contextId),
            'resourceVersion' => $this->plugin->getResourceVersion($this->contextId),
            'languageCode' => $this->plugin->getLanguageCode($this->contextId),
            'storeReservedDoi' => $this->plugin->getStoreReservedDoi($this->contextId),
            'automaticArticlePublish' => $this->plugin->getAutomaticArticlePublish($this->contextId),
            'automaticIssuePublish' => $this->plugin->getAutomaticIssuePublish($this->contextId),
        ]);
        parent::initData();
    }

    public function readInputData(): void
    {
        $this->readUserVars([
            'environment',
            'apiToken',
            'clearApiToken',
            'publisher',
            'journalIssn',
            'resourceVersion',
            'languageCode',
            'storeReservedDoi',
            'automaticArticlePublish',
            'automaticIssuePublish',
        ]);
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'hasApiToken' => $this->plugin->hasApiToken($this->contextId),
            'environmentOptions' => [
                'sandbox' => __('plugins.generic.zenodo.settings.environment.sandbox'),
                'production' => __('plugins.generic.zenodo.settings.environment.production'),
            ],
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $environment = (string) $this->getData('environment');
        $token = trim((string) $this->getData('apiToken'));
        $clearToken = (bool) $this->getData('clearApiToken');

        $this->plugin->updateSetting($this->contextId, 'environment', $environment, 'string');
        $this->plugin->updateSetting(
            $this->contextId,
            'publisher',
            trim((string) $this->getData('publisher')),
            'string'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'journalIssn',
            strtoupper(trim((string) $this->getData('journalIssn'))),
            'string'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'resourceVersion',
            trim((string) $this->getData('resourceVersion')),
            'string'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'languageCode',
            strtolower(trim((string) $this->getData('languageCode'))),
            'string'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'storeReservedDoi',
            (bool) $this->getData('storeReservedDoi'),
            'bool'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'automaticArticlePublish',
            (bool) $this->getData('automaticArticlePublish'),
            'bool'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'automaticIssuePublish',
            (bool) $this->getData('automaticIssuePublish'),
            'bool'
        );

        if ($clearToken) {
            $this->plugin->updateSetting($this->contextId, 'apiToken', '', 'string');
        } elseif ($token !== '') {
            $this->plugin->updateSetting($this->contextId, 'apiToken', $token, 'string');
        }

        return parent::execute(...$functionArgs);
    }
}
