<?php

namespace OpenEMR\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use OpenEMR\RestControllers\PatientRestController;
use OpenEMR\Tests\Fixtures\FixtureManager;

/**
 * Patient API Endpoint Test Cases.
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Dixon Whitmire <dixonwh@gmail.com>
 * @copyright Copyright (c) 2020 Dixon Whitmire <dixonwh@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 *
 */
#[CoversClass(PatientRestController::class)]
#[CoversMethod(PatientRestController::class, '::post')]
#[CoversMethod(PatientRestController::class, '::put')]
#[CoversMethod(PatientRestController::class, '::getOne')]
#[CoversMethod(PatientRestController::class, '::getAll')]
class PatientApiTest extends ApiTestCase
{
    const PATIENT_API_ENDPOINT = "/apis/default/api/patient";
    private $fixtureManager;
    private array $patientRecord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAuthToken();
        $this->fixtureManager = new FixtureManager();
        $this->patientRecord = (array) $this->fixtureManager->getSinglePatientFixture();
    }

    protected function tearDown(): void
    {
        $this->fixtureManager->removePatientFixtures();
        $this->testClient->cleanupRevokeAuth();
        $this->testClient->cleanupClient();
    }

    /**
     * with an invalid patient request
     */
    #[Test]
    public function testInvalidPost()
    {
        unset($this->patientRecord["fname"]);
        $actualResponse = $this->post(self::PATIENT_API_ENDPOINT, $this->patientRecord);
        $this->assertEquals(400, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * with a valid patient request
     */
    #[Test]
    public function testPost()
    {
        $actualResponse = $this->post(self::PATIENT_API_ENDPOINT, $this->patientRecord);

        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $newPatientPid = $responseBody["data"]["pid"];
        $this->assertIsInt($newPatientPid);
        $this->assertGreaterThan(0, $newPatientPid);

        $newPatientUuid = $responseBody["data"]["uuid"];
        $this->assertIsString($newPatientUuid);
    }

    /**
     * with an invalid pid and uuid
     */
    #[Test]
    public function testInvalidPut()
    {
        $actualResponse = $this->post(self::PATIENT_API_ENDPOINT, $this->patientRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $this->patientRecord["phone_home"] = "222-222-2222";
        $actualResponse = $this->put(self::PATIENT_API_ENDPOINT, "not-a-uuid", $this->patientRecord);

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
        $actualResponse = $this->post(self::PATIENT_API_ENDPOINT, $this->patientRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);

        $patientUuid = $responseBody["data"]["uuid"];

        $this->patientRecord["phone_home"] = "222-222-2222";
        $actualResponse = $this->put(self::PATIENT_API_ENDPOINT, $patientUuid, $this->patientRecord);

        $this->assertEquals(200, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $updatedResource = $responseBody["data"];
        $this->assertEquals($this->patientRecord["phone_home"], $updatedResource["phone_home"]);
    }

    /**
     * with an invalid pid
     */
    #[Test]
    public function testGetOneInvalidPid()
    {
        $actualResponse = $this->getOne(self::PATIENT_API_ENDPOINT, "not-a-uuid");
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
        $actualResponse = $this->post(self::PATIENT_API_ENDPOINT, $this->patientRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $patientUuid = $responseBody["data"]["uuid"];
        $patientPid = $responseBody["data"]["pid"];

        $actualResponse = $this->getOne(self::PATIENT_API_ENDPOINT, $patientUuid);
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals($patientUuid, $responseBody["data"]["uuid"]);
        $this->assertEquals($patientPid, $responseBody["data"]["pid"]);
    }

    /**
     * @covers ::getAll
     */
    #[Test]
    public function testGetAll()
    {
        $this->fixtureManager->installPatientFixtures();

        $actualResponse = $this->get(self::PATIENT_API_ENDPOINT, array("postal_code" => "90210"));
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $searchResults = $responseBody["data"];
        $this->assertGreaterThan(1, $searchResults);

        foreach ($searchResults as $index => $searchResult) {
            $this->assertEquals("90210", $searchResult["postal_code"]);
        }
    }
}
