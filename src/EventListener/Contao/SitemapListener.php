<?php

declare(strict_types=1);

namespace DVC\AdjustPlentaJobsSitemapByEmploymentType\EventListener\Contao;

use Contao\CoreBundle\Event\SitemapEvent;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Plenta\ContaoJobsBasic\Contao\Model\PlentaJobsBasicOfferModel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: SitemapEvent::class)]
class SitemapListener
{
    public function __invoke(SitemapEvent $event): void
    {
        $urls = [];

        foreach ($this->getSitemapLanguages($event) as $language) {
            foreach ($this->getPagesToAdd($language) as $url) {
                $urls[$url] = true;
            }
        }

        foreach (array_keys($urls) as $url) {
            $event->addUrlToDefaultUrlSet($url);
        }
    }

    /**
     * @return list<string>
     */
    private function getSitemapLanguages(SitemapEvent $event): array
    {
        $languages = [];

        foreach ($event->getRootPageIds() as $rootPageId) {
            $rootPage = PageModel::findPublishedById((int) $rootPageId)?->loadDetails();

            if (null === $rootPage) {
                continue;
            }

            $language = (string) ($rootPage->rootLanguage ?: $rootPage->language);

            if ('' === $language) {
                continue;
            }

            $languages[] = $language;
        }

        return array_values(array_unique($languages));
    }

    /**
     * @return list<string>
     */
    private function getPagesToAdd(string $language): array
    {
        $jobs = PlentaJobsBasicOfferModel::findAllPublished();

        if (null === $jobs) {
            return [];
        }

        $pages = [];

        foreach ($jobs as $job) {
            if ('noindex,nofollow' === $job->robots) {
                continue;
            }

            $url = $this->getAbsoluteUrl($job, $language);

            if (null !== $url) {
                $pages[] = $url;
            }
        }

        return $pages;
    }

    private function getReaderPage(PlentaJobsBasicOfferModel $job, string $language): ?PageModel
    {
        $modules = ModuleModel::findByType('plenta_jobs_basic_offer_list');

        if (null === $modules) {
            return null;
        }

        $jobLocations = $this->normalizeToArray(StringUtil::deserialize($job->jobLocation));
        $jobEmploymentTypes = $this->normalizeToArray(json_decode((string) $job->employmentType, true));

        foreach ($modules as $module) {
            $moduleLocations = $this->normalizeToArray(StringUtil::deserialize($module->plentaJobsBasicLocations));
            $moduleEmploymentTypes = $this->normalizeToArray(StringUtil::deserialize($module->plentaJobsBasicEmploymentTypes));

            $moduleMatchesLocation = [] !== array_intersect($moduleLocations, $jobLocations);
            $moduleMatchesEmploymentType = [] !== array_intersect($moduleEmploymentTypes, $jobEmploymentTypes);

            if (!$moduleMatchesLocation && !$moduleMatchesEmploymentType) {
                continue;
            }

            $jumpToPage = PageModel::findPublishedById((int) $module->jumpTo)?->loadDetails();

            if (null === $jumpToPage) {
                continue;
            }

            if ($jumpToPage->rootLanguage === $language) {
                return $jumpToPage;
            }
        }

        return null;
    }

    private function getAbsoluteUrl(PlentaJobsBasicOfferModel $job, string $language): ?string
    {
        $page = $this->getReaderPage($job, $language);

        if (null === $page) {
            return null;
        }

        return StringUtil::ampersand($page->getAbsoluteUrl($this->getParams($job, $language)));
    }

    private function getParams(PlentaJobsBasicOfferModel $job, string $language): string
    {
        $alias = $job->alias;
        $translation = $job->getTranslation($language);

        if (\is_array($translation) && isset($translation['alias'])) {
            $alias = (string) $translation['alias'];
        }

        return '/'.($alias ?: $job->id);
    }

    /**
     * @return list<string>
     */
    private function normalizeToArray(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => '' !== $item));
    }
}
