<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\SitesManager;

use Exception;
use Piwik\API\ResponseBuilder;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Plugin\Manager;
use Piwik\Plugins\SitesManager\SiteContentDetection\SiteContentDetectionAbstract;
use Piwik\Plugins\SitesManager\SiteContentDetection\WordPress;
use Piwik\SiteContentDetector;
use Piwik\Session;
use Piwik\SettingsPiwik;
use Piwik\Url;

/**
 *
 */
class Controller extends \Piwik\Plugin\ControllerAdmin
{
    /** @var SiteContentDetector */
    private $siteContentDetector;

    public function __construct(SiteContentDetector $siteContentDetector)
    {
        $this->siteContentDetector = $siteContentDetector;

        parent::__construct();
    }

    /**
     * Main view showing listing of websites and settings
     */
    public function index()
    {
        Piwik::checkUserHasSomeAdminAccess();
        SitesManager::dieIfSitesAdminIsDisabled();

        return $this->renderTemplate('index');
    }

    public function globalSettings()
    {
        Piwik::checkUserHasSuperUserAccess();

        return $this->renderTemplate('globalSettings');
    }

    public function getGlobalSettings()
    {
        Piwik::checkUserHasSomeViewAccess();

        $response = new ResponseBuilder(Common::getRequestVar('format'));

        $globalSettings = [];
        $globalSettings['keepURLFragmentsGlobal'] = API::getInstance()->getKeepURLFragmentsGlobal();
        $globalSettings['defaultCurrency'] = API::getInstance()->getDefaultCurrency();
        $globalSettings['searchKeywordParametersGlobal'] = API::getInstance()->getSearchKeywordParametersGlobal();
        $globalSettings['searchCategoryParametersGlobal'] = API::getInstance()->getSearchCategoryParametersGlobal();
        $globalSettings['defaultTimezone'] = API::getInstance()->getDefaultTimezone();
        $globalSettings['excludedIpsGlobal'] = API::getInstance()->getExcludedIpsGlobal();
        $globalSettings['excludedQueryParametersGlobal'] = API::getInstance()->getExcludedQueryParametersGlobal();
        $globalSettings['excludedUserAgentsGlobal'] = API::getInstance()->getExcludedUserAgentsGlobal();
        $globalSettings['excludedReferrersGlobal'] = API::getInstance()->getExcludedReferrersGlobal();

        return $response->getResponse($globalSettings);
    }

    /**
     * Records Global settings when user submit changes
     */
    public function setGlobalSettings()
    {
        $response = new ResponseBuilder(Common::getRequestVar('format'));

        try {
            $this->checkTokenInUrl();
            $timezone = Common::getRequestVar('timezone', false);
            $excludedIps = Common::getRequestVar('excludedIps', false);
            $excludedQueryParameters = Common::getRequestVar('excludedQueryParameters', false);
            $excludedUserAgents = Common::getRequestVar('excludedUserAgents', false);
            $excludedReferrers = Common::getRequestVar('excludedReferrers', false);
            $currency = Common::getRequestVar('currency', false);
            $searchKeywordParameters = Common::getRequestVar('searchKeywordParameters', $default = "");
            $searchCategoryParameters = Common::getRequestVar('searchCategoryParameters', $default = "");
            $keepURLFragments = Common::getRequestVar('keepURLFragments', $default = 0);

            $api = API::getInstance();
            $api->setDefaultTimezone($timezone);
            $api->setDefaultCurrency($currency);
            $api->setGlobalExcludedQueryParameters($excludedQueryParameters);
            $api->setGlobalExcludedIps($excludedIps);
            $api->setGlobalExcludedUserAgents($excludedUserAgents);
            $api->setGlobalExcludedReferrers($excludedReferrers);
            $api->setGlobalSearchParameters($searchKeywordParameters, $searchCategoryParameters);
            $api->setKeepURLFragmentsGlobal($keepURLFragments);

            $toReturn = $response->getResponse();
        } catch (Exception $e) {
            $toReturn = $response->getResponseException($e);
        }

        return $toReturn;
    }

    public function ignoreNoDataMessage()
    {
        Piwik::checkUserHasSomeViewAccess();

        $session = new Session\SessionNamespace('siteWithoutData');
        $session->ignoreMessage = true;
        $session->setExpirationSeconds($oneHour = 60 * 60);

        $url = Url::getCurrentUrlWithoutQueryString() . Url::getCurrentQueryStringWithParametersModified(array('module' => 'CoreHome', 'action' => 'index'));
        Url::redirectToUrl($url);
    }

    public function siteWithoutData()
    {
        $this->checkSitePermission();

        return $this->renderTemplateAs('siteWithoutData', [
            'emailBody'                                           => SitesManager::renderTrackingCodeEmail($this->idSite),
            'siteWithoutDataStartTrackingTranslationKey'          => StaticContainer::get('SitesManager.SiteWithoutDataStartTrackingTranslation'),
            'inviteUserLink'                                      => $this->getInviteUserLink()
        ], $viewType = 'basic');
    }

    public function siteWithoutDataTabs()
    {
        $this->checkSitePermission();

        $googleAnalyticsImporterMessage = '';
        if (!Manager::getInstance()->isPluginLoaded('GoogleAnalyticsImporter')) {
            $googleAnalyticsImporterMessage = '<h3>' . Piwik::translate('CoreAdminHome_ImportFromGoogleAnalytics') . '</h3>'
                . '<p>' . Piwik::translate('CoreAdminHome_ImportFromGoogleAnalyticsDescription', ['<a href="https://plugins.matomo.org/GoogleAnalyticsImporter" rel="noopener noreferrer" target="_blank">', '</a>']) . '</p>'
                . '<p></p>';

            /**
             * @ignore
             */
            Piwik::postEvent('SitesManager.siteWithoutData.customizeImporterMessage', [&$googleAnalyticsImporterMessage]);
        }

        $this->siteContentDetector->detectContent([], $this->idSite);

        $templateData = [
            'idSite'        => $this->idSite,
            'matomoUrl'      => Url::getCurrentUrlWithoutFileName(),
            'cms' => $this->siteContentDetector->getDetectsByType(SiteContentDetectionAbstract::TYPE_CMS),
            'trackers' => $this->siteContentDetector->getDetectsByType(SiteContentDetectionAbstract::TYPE_TRACKER),
            'jsFrameworks' => $this->siteContentDetector->getDetectsByType(SiteContentDetectionAbstract::TYPE_JS_FRAMEWORK),
            'consentManagers' => $this->siteContentDetector->getDetectsByType(SiteContentDetectionAbstract::TYPE_CONSENT_MANAGER),
            'instruction' => $this->getCmsInstruction(),
            'googleAnalyticsImporterMessage' => $googleAnalyticsImporterMessage,
        ];

        $templateData['tabs'] = [];
        $templateData['instructionUrls'] = [];
        $templateData['othersInstructions'] = [];

        $activeTab = null;
        $activeTabPriority = 1000;

        foreach ($this->siteContentDetector->getSiteContentDetectionsByType() as $detections) {
            foreach ($detections as $obj) {
                $tabContent        = $obj->renderInstructionsTab($this->siteContentDetector);
                $othersInstruction = $obj->renderOthersInstruction($this->siteContentDetector);
                $instructionUrl    = $obj->getInstructionUrl();

                /**
                 * Event that can be used to manipulate the content of a certain tab on the no data page
                 *
                 * @param string $tabContent  Content of the tab
                 */
                Piwik::postEvent('Template.siteWithoutDataTab.' . $obj::getId() . '.content', [&$tabContent]);
                /**
                 * Event that can be used to manipulate the content of a record on the others tab on the no data page
                 *
                 * @param string $othersInstruction  Content of the record
                 */
                Piwik::postEvent('Template.siteWithoutDataTab.' . $obj::getId() . '.others', [&$othersInstruction]);

                if (!empty($tabContent) && $obj->shouldShowInstructionTab($this->siteContentDetector)) {
                    $templateData['tabs'][] = [
                        'id'                => $obj::getId(),
                        'name'              => $obj::getName(),
                        'type'              => $obj::getContentType(),
                        'content'           => $tabContent,
                        'priority'          => $obj::getPriority(),
                    ];

                    if ($obj->shouldHighlightTabIfShown() && $obj::getPriority() < $activeTabPriority) {
                        $activeTab = $obj::getId();
                        $activeTabPriority = $obj::getPriority();
                    }
                }

                if (!empty($othersInstruction)) {
                    $templateData['othersInstructions'][] = [
                        'id'                => $obj::getId(),
                        'name'              => $obj::getName(),
                        'type'              => $obj::getContentType(),
                        'othersInstruction' => $othersInstruction,
                    ];
                }

                if (!empty($instructionUrl)) {
                    $templateData['instructionUrls'][] = [
                        'id'             => $obj::getId(),
                        'name'           => $obj::getName(),
                        'type'           => $obj::getContentType(),
                        'instructionUrl' => $obj::getInstructionUrl(),
                    ];
                }
            }
        }

        usort($templateData['tabs'], function($a, $b) {
            return strcmp($a['priority'], $b['priority']);
        });

        usort($templateData['othersInstructions'], function($a, $b) {
            return strnatcmp($a['name'], $b['name']);
        });

        usort($templateData['instructionUrls'], function($a, $b) {
            return strnatcmp($a['name'], $b['name']);
        });

        $templateData['activeTab'] = $activeTab;

        return $this->renderTemplateAs('_siteWithoutDataTabs', $templateData, $viewType = 'basic');
    }

    private function getCmsInstruction()
    {
        $detectedCMSes = $this->siteContentDetector->getDetectsByType(SiteContentDetectionAbstract::TYPE_CMS);

        if (empty($detectedCMSes)
            || $this->siteContentDetector->wasDetected(WordPress::getId())) {
            return '';
        }

        $detectedCms = $this->siteContentDetector->getSiteContentDetectionById(reset($detectedCMSes));

        if (null === $detectedCms) {
            return '';
        }

        return Piwik::translate(
            'SitesManager_SiteWithoutDataDetectedSite',
            [
                $detectedCms::getName(),
                '<a target="_blank" rel="noreferrer noopener" href="' . $detectedCms::getInstructionUrl() . '">',
                '</a>'
            ]
        );
    }

    private function getInviteUserLink()
    {
        $request = \Piwik\Request::fromRequest();
        $idSite = $request->getIntegerParameter('idSite', 0);
        if (!$idSite || !Piwik::isUserHasAdminAccess($idSite)) {
            return 'https://matomo.org/faq/general/manage-users/#imanadmin-creating-users';
        }

        return SettingsPiwik::getPiwikUrl() . 'index.php?' . Url::getQueryStringFromParameters([
                'idSite' => $idSite,
                'module' => 'UsersManager',
                'action' => 'index',
            ]);
    }
}
