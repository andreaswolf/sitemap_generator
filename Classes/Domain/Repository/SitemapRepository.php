<?php
/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Markussom\SitemapGenerator\Domain\Repository;

use Markussom\SitemapGenerator\Domain\Model\UrlEntry;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Class SitemapRepository
 * @package Markussom\SitemapGenerator\Domain\Repository
 */
class SitemapRepository
{

    /**
     * @var \TYPO3\CMS\Frontend\Page\PageRepository
     * @inject
     */
    protected $pageRepo = null;

    /**
     * @var ConfigurationManager
     */
    protected $configManager = null;

    /**
     * @var \TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder
     * @inject
     */
    protected $uriBuilder = null;

    /**
     * @var ContentObjectRenderer
     */
    protected $contentObject;

    /**
     * @var TypoScriptParser
     */
    protected $typoScriptParser;

    /**
     * @var array
     */
    protected $pluginConfig = [];

    /**
     * SitemapRepository constructor.
     * @SuppressWarnings(superglobals)
     */
    public function __construct()
    {
        $this->contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $this->typoScriptParser = GeneralUtility::makeInstance(TypoScriptParser::class);

        $this->pluginConfig = $this->typoScriptParser->getVal(
            'plugin.tx_sitemapgenerator',
            $GLOBALS['TSFE']->tmpl->setup
        );
    }

    /**
     * @return array
     */
    public function findAllPages()
    {
        $pages = $this->getPages();
        $urlEntries = $this->getEntriesFromPages($pages);

        return $urlEntries;
    }

    /**
     * @return array
     */
    public function findAllEntries()
    {
        $entries = $this->findAllPages();
        $typoScript = $this->generateEntriesFromTypoScript();
        return array_merge($entries, $typoScript);
    }

    /**
     * @return array
     */
    public function generateEntriesFromTypoScript()
    {
        $urlEntries = $this->pluginConfig[1]['urlEntries.'];
        $entries = [];
        foreach ($urlEntries as $urlEntry) {
            if (is_array($urlEntry)) {
                $entries = $this->mapToEntries($urlEntry);
            }
        }
        return $entries;

    }

    /**
     * @param array $typoScriptUrlEntry
     * @SuppressWarnings(superglobals)
     * @return array
     */
    protected function mapToEntries(array $typoScriptUrlEntry)
    {
        if ($typoScriptUrlEntry['table'] && $typoScriptUrlEntry['active'] == 1) {

            $records = $this->getRecordsFromDatabase($typoScriptUrlEntry);
            $urlEntries = [];
            if ($this->getDatabaseConnection()->sql_num_rows($records)) {
                while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($records)) {
                    $urlEntry = new UrlEntry();
                    $urlEntry->setLoc($this->generateUrlFromTypoScript($row));
                    if ($typoScriptUrlEntry['lastmod']) {
                        $urlEntry->setLastmod(date('Y-m-d', $row[$typoScriptUrlEntry['lastmod']]));
                    }
                    if ($typoScriptUrlEntry['changefreq']) {
                        $urlEntry->setChangefreq($row[$typoScriptUrlEntry['changefreq']]);
                    }
                    if ($typoScriptUrlEntry['priority']) {
                        $urlEntry->setPriority($row[$typoScriptUrlEntry['priority']]);
                    }
                    $urlEntries[] = $urlEntry;
                }
            }
            return $urlEntries;
        }
        return [];

    }

    /**
     * @param $row
     * @return string
     * @SuppressWarnings(superglobals)
     */
    public function generateUrlFromTypoScript($row)
    {
        $url = '';
        $entriesConfiguration = $this->pluginConfig[1]['urlEntries.'];
        foreach ($entriesConfiguration as $item) {
            if (!empty($item['table'])) {
                $this->contentObject->start($row, $item['table']);
                $url = $this->contentObject->cObjGetSingle($item['url'], $item['url.']);
            }
        }
        return $url;
    }

    /**
     * Get pages from Database
     *
     * @return array
     */
    private function getPages()
    {
        $startPage = $this->pageRepo->getPage($this->pluginConfig['1']['urlEntries.']['pages.']['rootPageId']);
        $pages = $this->pageRepo->getMenu(
            $this->pluginConfig['1']['urlEntries.']['pages.']['rootPageId'],
            '*',
            'sorting',
            $this->pageRepo->enableFields('pages') . 'AND ' . UrlEntry::EXCLUDE_FROM_SITEMAP . '!=1'
        );
        return array_merge($startPage, $pages);
    }

    /**
     * @param $pages
     * @return array
     */
    private function getEntriesFromPages($pages)
    {
        $urlEntries = '';
        foreach ($pages as $page) {
            if ($page['doktype'] == 1) {
                $urlEntry = new UrlEntry();
                $uri = $this->uriBuilder->reset()->setTargetPageUid($page['uid'])->setCreateAbsoluteUri(true)->build();
                $urlEntry->setLoc($uri);
                $urlEntry->setLastmod(date('Y-m-d', $page['tstamp']));
                if ($page['sitemap_priority']) {
                    $urlEntry->setPriority('0.' . $page['sitemap_priority']);
                }
                $urlEntries[] = $urlEntry;
            }
        }
        return $urlEntries;
    }

    /**
     * Returns the database connection
     *
     * @SuppressWarnings(superglobals)
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * Get records from database
     *
     * @param $typoScriptUrlEntry
     * @return bool|\mysqli_result|object
     */
    private function getRecordsFromDatabase($typoScriptUrlEntry)
    {
        return $this->getDatabaseConnection()->exec_SELECTquery(
            '*',
            $typoScriptUrlEntry['table'],
            'pid!=0' . $typoScriptUrlEntry['additionalWhere'] . $this->pageRepo->enableFields(
                $typoScriptUrlEntry['table']
            )
        );
    }
}
