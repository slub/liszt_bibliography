<?php

namespace Slub\LisztBibliography\Tests\Unit\Processing;

use Slub\LisztBibliography\Processing\BibElasticMapping;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @covers Slub\LisztBibliography\Processing\BibElasticMapping
 */
final class BibElasticMappingTest extends UnitTestCase
{
    /**
     * @test
     */
    public function indexIsCreatedWithoutReplicas(): void
    {
        $params = BibElasticMapping::getMappingParams('zotero_test');

        // The index runs on a single node, so a replica could never be assigned.
        // The data is rebuilt from Zotero and needs no failover. We skip replicas
        // to keep the cluster health green, so health checks can report real problems.
        self::assertSame(0, $params['body']['settings']['number_of_replicas']);
    }
}
