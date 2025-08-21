<?php
/*
 * UtilsServiceTest.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2025 Stephen Nielson <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Tests\Services\FHIR;

use OpenEMR\Services\FHIR\UtilsService;
use PHPUnit\Framework\TestCase;

class UtilsServiceTest extends TestCase {

    public function testGetLocalDateAsUTC()
    {
        $this->markTestIncomplete("Test is not complete");
    }

    public function testGetDateFormattedAsUTC()
    {
        $this->markTestIncomplete("Test is not complete");
    }

    public function testGetLocalTimestampAsUTCDate()
    {
        $this->markTestIncomplete("Test is not complete");
    }

    public function testCreatePeriod(): void
    {
        // TODO: @adunsulag need to flesh this out more, but for now we do a smoke test to ensure it doesn't throw an error
        $period = UtilsService::createPeriod("2025-01-01T00:00:00Z", "2025-12-31T23:59:59Z");
        $this->assertNotEmpty($period->getStart(), "Period start should not be empty");
        $this->assertNotEmpty($period->getEnd(), "Period end should not be empty");
        $this->assertEquals("2025-01-01T00:00:00+00:00", $period->getStart()->getValue(), "Period start date should match");
        $this->assertEquals("2025-12-31T23:59:59+00:00", $period->getEnd()->getValue(), "Period end date should match");
    }

    public function testCreatePeriodWithNoEndDate() {
        $period = UtilsService::createPeriod("2025-01-01T00:00:00Z");
        $this->assertNotEmpty($period->getStart(), "Period start should not be empty");
        $this->assertEmpty($period->getEnd(), "Period end should be empty when no end date is provided");
        $this->assertEquals("2025-01-01T00:00:00+00:00", $period->getStart()->getValue(), "Period start date should match");
    }
}
