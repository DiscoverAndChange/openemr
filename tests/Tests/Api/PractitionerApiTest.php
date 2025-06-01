<?php

namespace OpenEMR\Tests\Api;

use OpenEMR\RestControllers\PractitionerRestController;
use OpenEMR\Tests\Fixtures\PractitionerFixtureManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Practitioner API Endpoint Test Cases.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 *
 */
#[CoversClass(PractitionerRestController::class)]
#[CoversMethod(PractitionerRestController::class, '::post')]
#[CoversMethod(PractitionerRestController::class, '::put')]
#[CoversMethod(PractitionerRestController::class, '::getOne')]
#[CoversMethod(PractitionerRestController::class, '::getAll')]
class PractitionerApiTest extends ApiTestCase
{
    const PRACTITIONER_API_ENDPOINT = "/apis/default/api/practitioner";

    /**
     * @var ApiTestClient
     */
    private $fixtureManager;

    private $practitionerRecord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAuthToken();
        $this->fixtureManager = new PractitionerFixtureManager();
        $this->practitionerRecord = (array) $this->fixtureManager->getSinglePractitionerFixture();
    }

    protected function tearDown(): void
    {
        $this->fixtureManager->removePractitionerFixtures();
        parent::tearDown();
    }

    /**
     * with an invalid practitioner request
     */
    #[Test]
    public function testInvalidPost()
    {
        unset($this->practitionerRecord["fname"]);
        $actualResponse = $this->post(self::PRACTITIONER_API_ENDPOINT, $this->practitionerRecord);

        $this->assertEquals(400, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * with a valid practitioner request
     */
    #[Test]
    public function testPost()
    {
        $actualResponse = $this->post(self::PRACTITIONER_API_ENDPOINT, $this->practitionerRecord);

        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $newPractitionerId = $responseBody["data"]["id"];
        $this->assertIsInt($newPractitionerId);
        $this->assertGreaterThan(0, $newPractitionerId);

        $newPractitionerUuid = $responseBody["data"]["uuid"];
        $this->assertIsString($newPractitionerUuid);
    }

    /**
     * with an invalid pid and uuid
     */
    #[Test]
    public function testInvalidPut()
    {
        $actualResponse = $this->post(self::PRACTITIONER_API_ENDPOINT, $this->practitionerRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $this->practitionerRecord["email"] = "help@pennfirm.com";
        $actualResponse = $this->put(
            self::PRACTITIONER_API_ENDPOINT,
            "not-a-uuid",
            $this->practitionerRecord
        );

        $this->assertEquals(400, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * with a valid resource id and payload
     */
    #[Test]
    public function testPut()
    {
        $actualResponse = $this->post(self::PRACTITIONER_API_ENDPOINT, $this->practitionerRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);

        $practitionerUuid = $responseBody["data"]["uuid"];

        $this->practitionerRecord["email"] = "help@pennfirm.com";
        $actualResponse = $this->put(self::PRACTITIONER_API_ENDPOINT, $practitionerUuid, $this->practitionerRecord);

        $this->assertEquals(200, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $updatedResource = $responseBody["data"];

        $this->assertEquals($this->practitionerRecord["email"], $updatedResource["email"]);
    }

    /**
     * with an invalid pid
     */
    #[Test]
    public function testGetOneInvalidId()
    {
        $actualResponse = $this->getOne(self::PRACTITIONER_API_ENDPOINT, "not-a-uuid");
        $this->assertEquals(400, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * with a valid pid
     */
    #[Test]
    public function testGetOne()
    {
        $actualResponse = $this->post(self::PRACTITIONER_API_ENDPOINT, $this->practitionerRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $practitionerUuid = $responseBody["data"]["uuid"];
        $practitionerId = $responseBody["data"]["id"];

        $actualResponse = $this->getOne(self::PRACTITIONER_API_ENDPOINT, $practitionerUuid);
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals($practitionerUuid, $responseBody["data"]["uuid"]);
        $this->assertEquals($practitionerId, $responseBody["data"]["id"]);
    }


    /**
     *
     */
    #[Test]
    public function testGetAll()
    {
        $this->fixtureManager->installPractitionerFixtures();

        $actualResponse = $this->get(self::PRACTITIONER_API_ENDPOINT, array("npi" => "0123456789"));
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $searchResults = $responseBody["data"];
        $this->assertGreaterThan(1, $searchResults);

        foreach ($searchResults as $index => $searchResult) {
            $this->assertEquals("0123456789", $searchResult["npi"]);
        }
    }
}
