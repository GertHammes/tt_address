<?php

namespace FriendsOfTYPO3\TtAddress\Controller;

/**
 * This file is part of the "tt_address" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
use FriendsOfTYPO3\TtAddress\Domain\Model\Dto\Demand;
use FriendsOfTYPO3\TtAddress\Domain\Repository\AddressRepository;
use FriendsOfTYPO3\TtAddress\Utility\TypoScript;
use TYPO3\CMS\Core\Database\QueryGenerator;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;

/**
 * AddressController
 */
class AddressController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    /** @var AddressRepository */
    protected $addressRepository;

    /** @var QueryGenerator */
    protected $queryGenerator;

    public function initializeAction()
    {
        $this->queryGenerator = GeneralUtility::makeInstance(QueryGenerator::class);
    }

    /**
     * @param \FriendsOfTYPO3\TtAddress\Domain\Model\Address $address
     */
    public function showAction(\FriendsOfTYPO3\TtAddress\Domain\Model\Address $address = null)
    {
        if ($address === null) {
            $this->redirectToUri($this->uriBuilder->reset()->setTargetPageUid($GLOBALS['TSFE']->id)->build());
        }
        $this->view->assign('address', $address);
    }

    /**
     * Lists addresses by settings in waterfall principle.
     * singleRecords take precedence over categories which take precedence over records from pages
     *
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function listAction()
    {
        $demand = $this->createDemandFromSettings();
        if ($demand->getSingleRecords()) {
            $addresses = $this->addressRepository->getAddressesByCustomSorting($demand);
        } else {
            $addresses = $this->addressRepository->findByDemand($demand);
        }

        $this->view->assignMultiple([
            'demand' => $demand,
            'addresses' => $addresses
        ]);
    }

    /**
     * Injects the Configuration Manager and is initializing the framework settings
     *
     * @param ConfigurationManagerInterface $configurationManager Instance of the Configuration Manager
     */
    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager)
    {
        $this->configurationManager = $configurationManager;

        // get the whole typoscript (_FRAMEWORK does not work anymore, don't know why)
        $tsSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
            '',
            ''
        );

        // correct the array to be in same shape like the _SETTINGS array
        $tsSettings = $this->removeDots($tsSettings['plugin.']['tx_ttaddress.']);

        // get original settings
        // original means: what extbase does by munching flexform and TypoScript together, but leaving empty flexform-settings empty ...
        $originalSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS
        );

        $propertiesNotAllowedViaFlexForms = ['orderByAllowed'];
        foreach ($propertiesNotAllowedViaFlexForms as $property) {
            $originalSettings[$property] = $tsSettings['settings'][$property];
        }

        // start override
        if (isset($tsSettings['settings']['overrideFlexformSettingsIfEmpty'])) {
            $typoScriptUtility = GeneralUtility::makeInstance(TypoScript::class);
            $originalSettings = $typoScriptUtility->override($originalSettings, $tsSettings);
        }
        // Re-set global settings
        $this->settings = $originalSettings;
    }

    protected function createDemandFromSettings(): Demand
    {
        $demand = $this->objectManager->get(Demand::class);
        $demand->setCategories((string)$this->settings['groups']);
        $categoryCombination = (int)$this->settings['groupsCombination'] === 1 ? 'or' : 'and';
        $demand->setCategoryCombination($categoryCombination);

        if ($this->settings['pages']) {
            $demand->setPages($this->getPidList());
        }
        $demand->setSingleRecords((string)$this->settings['singleRecords']);
        $demand->setSortBy((string)$this->settings['sortBy']);
        $demand->setSortOrder((string)$this->settings['sortOrder']);

        return $demand;
    }

    /**
     * @param AddressRepository $addressRepository
     */
    public function injectAddressRepository(AddressRepository $addressRepository)
    {
        $this->addressRepository = $addressRepository;
    }

    /**
     * Initializes the view before invoking an action method.
     *
     * @param ViewInterface $view The view to be initialized
     */
    protected function initializeView(ViewInterface $view)
    {
        $view->assign('contentObjectData', $this->configurationManager->getContentObject()->data);
        parent::initializeView($view);
    }

    /**
     * Removes dots at the end of a configuration array
     *
     * @param array $settings the array to transformed
     * @return array $settings the transformed array
     */
    protected function removeDots(array $settings): array
    {
        $conf = [];
        foreach ($settings as $key => $value) {
            $conf[$this->removeDotAtTheEnd($key)] = \is_array($value) ? $this->removeDots($value) : $value;
        }
        return $conf;
    }

    /**
     * Removes a dot in the end of a String
     *
     * @param string $string
     * @return string
     */
    protected function removeDotAtTheEnd($string): string
    {
        return preg_replace('/\.$/', '', $string);
    }

    /**
     * Retrieves subpages of given pageIds recursively until reached $this->settings['recursive']
     *
     * @return array an array with all pageIds
     */
    protected function getPidList(): array
    {
        $rootPIDs = explode(',', $this->settings['pages']);
        $pidList = $rootPIDs;

        // iterate through root-page ids and merge to array
        foreach ($rootPIDs as $pid) {
            $result = $this->queryGenerator->getTreeList($pid, $this->settings['recursive'], 0, 1);
            if ($result) {
                $subtreePids = explode(',', $result);
                $pidList = array_merge($pidList, $subtreePids);
            }
        }
        return $pidList;
    }
}
