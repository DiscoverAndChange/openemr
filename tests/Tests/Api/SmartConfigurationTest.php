<?php

namespace OpenEMR\Tests\Api;

use OpenEMR\RestControllers\SMART\SMARTConfigurationController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Capability FHIR Endpoint Test Cases.
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 *
 */
#[CoversClass(SMARTConfigurationController::class)]
#[CoversMethod(SMARTConfigurationController::class, '::getConfig')]
class SmartConfigurationTest extends ApiTestCase
{
    const SMART_CONFIG_ENDPOINT = "/apis/default/fhir/.well-known/smart-configuration";

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @covers ::get with an invalid path
     */
    #[Test]
    public function testInvalidPathGet()
    {
        $actualResponse = $this->get(self::SMART_CONFIG_ENDPOINT . "ss");
        $this->assertEquals(401, $actualResponse->getStatusCode());
    }

    /**
     *
     */
    #[Test]
    public function testGet()
    {
        $actualResponse = $this->get(self::SMART_CONFIG_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
    }
}
