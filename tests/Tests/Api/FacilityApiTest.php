<?php

namespace OpenEMR\Tests\Api;

use OpenEMR\RestControllers\FacilityRestController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use OpenEMR\Tests\Fixtures\FacilityFixtureManager;

/**
 * Facility API Endpoint Test Cases.
 * @coversDefaultClass \OpenEMR\RestControllers\FacilityRestController
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 *
 */
#[CoversClass(FacilityRestController::class)]
#[CoversMethod(FacilityRestController::class, '::post')]
#[CoversMethod(FacilityRestController::class, '::put')]
#[CoversMethod(FacilityRestController::class, '::getOne')]
#[CoversMethod(FacilityRestController::class, '::getAll')]
class FacilityApiTest extends ApiTestCase
{
    const FACILITY_API_ENDPOINT = "/apis/default/api/facility";

    /**
     * @var FacilityFixtureManager
     */
    private $fixtureManager;

    private $facilityRecord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAuthToken();
        $this->fixtureManager = new FacilityFixtureManager();
        $this->facilityRecord = (array) $this->fixtureManager->getSingleFacilityFixture();
    }

    protected function tearDown(): void
    {
        $this->fixtureManager->removeFixtures();
        parent::tearDown();
    }

    /**
     * with an invalid facility request
     */
    #[Test]
    public function testInvalidPost()
    {
        unset($this->facilityRecord["name"]);
        $actualResponse = $this->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);

        $this->assertEquals(400, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * with a valid facility request
     */
    #[Test]
    public function testPost()
    {
        $actualResponse = $this->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);

        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $newFacilityId = $responseBody["data"]["id"];
        $this->assertIsInt($newFacilityId);
        $this->assertGreaterThan(0, $newFacilityId);

        $newFacilityUuid = $responseBody["data"]["uuid"];
        $this->assertIsString($newFacilityUuid);
    }

    /**
     * with an invalid uuid
     */
    #[Test]
    public function testInvalidPut()
    {
        $actualResponse = $this->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $this->facilityRecord["email"] = "help@pennfirm.com";
        $actualResponse = $this->put(
            self::FACILITY_API_ENDPOINT,
            "not-a-uuid",
            $this->facilityRecord
        );

        $this->assertEquals(400, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * with a valid resource uuid and payload
     */
    #[Test]
    public function testPut()
    {
        $actualResponse = $this->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);

        $facilityUuid = $responseBody["data"]["uuid"];

        $this->facilityRecord["email"] = "help@pennfirm.com";
        $actualResponse = $this->put(self::FACILITY_API_ENDPOINT, $facilityUuid, $this->facilityRecord);

        $this->assertEquals(200, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $updatedResource = $responseBody["data"];
        $this->assertEquals($this->facilityRecord["email"], $updatedResource["email"]);
    }

    /**
     * with an invalid uuid
     */
    #[Test]
    public function testGetOneInvalidId()
    {
        $actualResponse = $this->getOne(self::FACILITY_API_ENDPOINT, "not-a-uuid");
        $this->assertEquals(400, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * with a valid uuid
     */
    #[Test]
    public function testGetOne()
    {
        $actualResponse = $this->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $facilityUuid = $responseBody["data"]["uuid"];
        $facilityId = $responseBody["data"]["id"];

        $actualResponse = $this->getOne(self::FACILITY_API_ENDPOINT, $facilityUuid);
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals($facilityUuid, $responseBody["data"]["uuid"]);
        $this->assertEquals($facilityId, $responseBody["data"]["id"]);
    }


    /**
     *
     */
    #[Test]
    public function testGetAll()
    {
        $this->fixtureManager->installFacilityFixtures();

        $actualResponse = $this->get(self::FACILITY_API_ENDPOINT, array("facility_npi" => "0123456789"));
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $searchResults = $responseBody["data"];
        $this->assertGreaterThan(1, $searchResults);

        foreach ($searchResults as $index => $searchResult) {
            $this->assertEquals("0123456789", $searchResult["facility_npi"]);
        }
    }
}
