<?php declare(strict_types=1);

namespace Elgentos\OhDearChecks\Model\SearchEngine;

/**
 * Tests the connection of a search engine. Add an implementation for another engine to the
 * "testers" argument of Elgentos\OhDearChecks\Checks\SearchEngine in di.xml.
 */
interface ConnectionTesterInterface
{
    /**
     * @return bool|null true when reachable, false when not, null when this tester does not know the engine
     * @throws \Throwable when the client reports a failed connection with an exception
     */
    public function test(string $engine): ?bool;
}
