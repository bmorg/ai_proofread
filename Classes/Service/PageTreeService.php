<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the page UIDs of a backend page-tree subtree, honoring the current
 * backend user's read permissions.
 *
 * Replaces the @internal PageTreeView (used previously in both the module
 * controller and the subtree command) with a plain QueryBuilder breadth-first
 * walk: stable public API, identical across v11–v13, and explicit permission
 * handling via getPagePermsClause(). Deleted pages are excluded; hidden pages are
 * kept (editors see them in the BE tree), matching the previous behavior.
 */
final class PageTreeService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * The root page plus all descendant page UIDs the current BE user may see,
     * breadth-first order not guaranteed (callers treat it as a set).
     *
     * @return list<int>
     */
    public function descendantPageUids(int $rootPageUid): array
    {
        if ($rootPageUid <= 0) {
            return [];
        }

        $permsClause = $GLOBALS['BE_USER']->getPagePermsClause(Permission::PAGE_SHOW);

        $uids = [$rootPageUid];
        $pending = [$rootPageUid];
        while ($pending !== []) {
            $parent = array_shift($pending);
            foreach ($this->childUids($parent, $permsClause) as $childUid) {
                if (!\in_array($childUid, $uids, true)) {
                    $uids[] = $childUid;
                    $pending[] = $childUid;
                }
            }
        }

        return $uids;
    }

    /**
     * Direct child page UIDs of a page: permission-filtered, deleted excluded.
     *
     * @return list<int>
     */
    private function childUids(int $parentUid, string $permsClause): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(
            GeneralUtility::makeInstance(DeletedRestriction::class)
        );

        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                QueryHelper::stripLogicalOperatorPrefix($permsClause)
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn ($uid): int => (int)$uid, $rows);
    }
}
