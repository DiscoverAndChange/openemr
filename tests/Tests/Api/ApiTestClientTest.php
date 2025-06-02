<?php

namespace OpenEMR\Tests\Api;

use OpenEMR\RestControllers\AuthorizationController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Test cases for the OpenEMR Api Test Client
 * NOTE: currently disabled (by naming convention) until work is completed to support running as part of Travis CI
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Dixon Whitmire <dixonwh@gmail.com>
 * @copyright Copyright (c) 2020 Dixon Whitmire <dixonwh@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 * TODO: this tests the api requests for authorization controller as well, not really a true unit test.
 */
#[CoversClass(ApiTestClient::class)]
#[CoversClass(AuthorizationController::class)]
#[CoversMethod(ApiTestClient::class, '::getConfig')]
#[CoversMethod(ApiTestClient::class, '::setAuthToken')]
#[CoversMethod(ApiTestClient::class, '::removeAuthToken')]
class ApiTestClientTest extends ApiTestCase
{
    const EXAMPLE_API_ENDPOINT = "/apis/default/api/facility";
    const EXAMPLE_API_ENDPOINT_INVALID_SITE = "/apis/baddefault/api/facility";
    const EXAMPLE_API_ENDPOINT_SCOPE = "user/facility.read";
    const API_ROUTE_SCOPE = "api:oemr";

    /**
     * Configures the test client using environment variables and reasonable defaults
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * with a null value
     */
    #[Test]
    public function testGetConfigWithNull()
    {
        $this->setAuthToken();
        $this->expectException(\InvalidArgumentException::class);
        $this->testClient->getConfig(null);
    }

    /**
     * for HTTP client settings
     */
    #[Test]
    public function testGetConfig()
    {
        $this->setAuthToken();
        $this->assertFalse($this->testClient->getConfig("http_errors"));
        $this->assertEquals(10, $this->testClient->getConfig("timeout"));
        $this->assertNotNull($this->testClient->getConfig("base_uri"));

        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertEquals("application/json", $actualHeaders["Accept"]);
        $this->assertArrayHasKey("User-Agent", $actualHeaders);
    }

    /**
     * Tests the automated testing when invalid credentials arguments are provided
     * with invalid credential argument
     */
    #[Test]
    public function testApiAuthInvalidArgs()
    {
        try {
            $this->testClient->setAuthToken(ApiTestClient::OPENEMR_AUTH_ENDPOINT, array("foo" => "bar"), 'private', $this->getCoverageId());
            $this->assertFalse(true, "expected InvalidArgumentException");
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        $this->testClient->cleanupClient();

        try {
            $this->testClient->setAuthToken(ApiTestClient::OPENEMR_AUTH_ENDPOINT, array("username" => "bar"), 'private', $this->getCoverageId());
            $this->assertFalse(true, "expected InvalidArgumentException");
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }
    /**
     * Tests OpenEMR OAuth when invalid client id is provided
     * with invalid credentials
     */
    #[Test]
    public function testApiAuthInvalidClientId()
    {
        $actualValue = $this->testClient->setAuthToken(
            ApiTestClient::OPENEMR_AUTH_ENDPOINT,
            ["client_id" => ApiTestClient::BOGUS_CLIENTID]
            ,'private'
            ,$this->getCoverageId()
        );
        $this->assertEquals(401, $actualValue->getStatusCode());
        $this->assertEquals('invalid_client', json_decode($actualValue->getBody())->error);
    }

    /**
     * Tests OpenEMR OAuth when invalid user credentials are provided
     * with invalid credentials
     */
    #[Test]
    public function testApiAuthInvalidUserCredentials()
    {
        $actualValue = $this->testClient->setAuthToken(
            ApiTestClient::OPENEMR_AUTH_ENDPOINT,
            array("username" => "bar", "password" => "boo")
            ,'private'
            ,$this->getCoverageId()
        );
        $this->assertEquals(400, $actualValue->getStatusCode());
        $this->assertEquals('Failed Authentication', json_decode($actualValue->getBody())->hint);
    }

    /**
     * Tests OpenEMR API Auth for the REST and FHIR APIs
     */
    #[Test]
    public function testApiAuth()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayHasKey("Authorization", $actualHeaders);

        $authHeaderValue = substr($actualHeaders["Authorization"], 7);
        $this->assertGreaterThan(10, strlen($authHeaderValue));

        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    /**
     * Tests OpenEMR API Auth for the REST and FHIR APIs (test refresh request after the auth)
     */
    #[Test]
    public function testApiAuthThenRefresh()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayHasKey("Authorization", $actualHeaders);

        $authHeaderValue = substr($actualHeaders["Authorization"], 7);
        $this->assertGreaterThan(10, strlen($authHeaderValue));

        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);

        $refreshBody = [
            "grant_type" => "refresh_token",
            "client_id" => $this->testClient->getClientId(),
            "refresh_token" => $this->testClient->getRefreshToken()
        ];
        $this->testClient->setHeaders(
            [
            "Accept" => "application/json",
            "Content-Type" => "application/x-www-form-urlencoded"
            ]
        );
        $authResponse = $this->post(ApiTestClient::OAUTH_TOKEN_ENDPOINT, $refreshBody, false);
        // set headers back to default
        $this->testClient->setHeaders(
            [
            "Accept" => "application/json",
            "Content-Type" => "application/json"
            ]
        );
        $this->assertEquals(200, $authResponse->getStatusCode());
        $responseBody = json_decode($authResponse->getBody());
        $this->assertGreaterThan(10, strlen($responseBody->id_token));
        $this->assertGreaterThan(10, strlen($responseBody->access_token));
        $this->assertGreaterThan(10, strlen($responseBody->refresh_token));
    }

    /**
     * Tests OpenEMR API Auth for the REST and FHIR APIs (test refresh request after the auth with bad refresh token)
     */
    #[Test]
    public function testApiAuthThenBadRefresh()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayHasKey("Authorization", $actualHeaders);

        $authHeaderValue = substr($actualHeaders["Authorization"], 7);
        $this->assertGreaterThan(10, strlen($authHeaderValue));

        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);

        $refreshBody = [
            "grant_type" => "refresh_token",
            "client_id" => $this->testClient->getClientId(),
            "refresh_token" => ApiTestClient::BOGUS_REFRESH_TOKEN
        ];
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/x-www-form-urlencoded"
            ]
        );
        $authResponse = $this->testClient->post(ApiTestClient::OAUTH_TOKEN_ENDPOINT, $refreshBody, false);
        // set headers back to default
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/json"
            ]
        );
        $this->assertEquals(401, $authResponse->getStatusCode());
    }

    /**
     * Tests OpenEMR API Example Endpoint After Getting Auth for the REST and FHIR APIs
     */
    #[Test]
    public function testApiAuthExampleUse()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    /**
     * Tests OpenEMR API Example Endpoint After Getting Auth for the REST and FHIR APIs (also does a
     *  token refresh and use with new token)
     */
    #[Test]
    public function testApiAuthExampleUseThenRefreshThenUse()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);

        $refreshBody = [
            "grant_type" => "refresh_token",
            "client_id" => $this->testClient->getClientId(),
            "refresh_token" => $this->testClient->getRefreshToken()
        ];
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/x-www-form-urlencoded"
            ]
        );
        $authResponse = $this->post(ApiTestClient::OAUTH_TOKEN_ENDPOINT, $refreshBody, false);
        // set headers back to default
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/json"
            ]
        );
        $this->assertEquals(200, $authResponse->getStatusCode());
        $responseBody = json_decode($authResponse->getBody());
        $this->assertGreaterThan(10, strlen($responseBody->id_token));
        $this->assertGreaterThan(10, strlen($responseBody->access_token));
        $this->assertGreaterThan(10, strlen($responseBody->refresh_token));
        $this->testClient->setBearer($responseBody->access_token);

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    /**
     * Tests OpenEMR API Example Endpoint After Getting Auth for the REST and FHIR APIs (also does a
     *  token refresh and use with new token) with missing route scope
     */
    #[Test]
    public function testApiAuthExampleUseThenRefreshThenUseWithMissingRouteScope()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);

        // remove the route scope
        $scopeCustom = str_replace(self::API_ROUTE_SCOPE, '', ApiTestClient::ALL_SCOPES);

        $refreshBody = [
            "grant_type" => "refresh_token",
            "client_id" => $this->testClient->getClientId(),
            "scope" => $scopeCustom,
            "refresh_token" => $this->testClient->getRefreshToken()
        ];
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/x-www-form-urlencoded"
            ]
        );
        $authResponse = $this->post(ApiTestClient::OAUTH_TOKEN_ENDPOINT, $refreshBody, false);
        // set headers back to default
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/json"
            ]
        );
        $this->assertEquals(200, $authResponse->getStatusCode());
        $responseBody = json_decode($authResponse->getBody());
        $this->assertGreaterThan(10, strlen($responseBody->id_token));
        $this->assertGreaterThan(10, strlen($responseBody->access_token));
        $this->assertGreaterThan(10, strlen($responseBody->refresh_token));
        $this->testClient->setBearer($responseBody->access_token);

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(401, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    /**
     * Tests OpenEMR API Example Endpoint After Getting Auth for the REST and FHIR APIs (also does a
     *  token refresh and use with new token) with missing endpoint scope
     */
    #[Test]
    public function testApiAuthExampleUseThenRefreshThenUseWithMissingEndpointScope()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);

        // remove the endpoint scope
        $scopeCustom = str_replace(self::EXAMPLE_API_ENDPOINT_SCOPE, '', ApiTestClient::ALL_SCOPES);

        $refreshBody = [
            "grant_type" => "refresh_token",
            "client_id" => $this->testClient->getClientId(),
            "scope" => $scopeCustom,
            "refresh_token" => $this->testClient->getRefreshToken()
        ];
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/x-www-form-urlencoded"
            ]
        );
        $authResponse = $this->post(ApiTestClient::OAUTH_TOKEN_ENDPOINT, $refreshBody, false);
        // set headers back to default
        $this->testClient->setHeaders(
            [
                "Accept" => "application/json",
                "Content-Type" => "application/json"
            ]
        );
        $this->assertEquals(200, $authResponse->getStatusCode());
        $responseBody = json_decode($authResponse->getBody());
        $this->assertGreaterThan(10, strlen($responseBody->id_token));
        $this->assertGreaterThan(10, strlen($responseBody->access_token));
        $this->assertGreaterThan(10, strlen($responseBody->refresh_token));
        $this->testClient->setBearer($responseBody->access_token);

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(401, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    /**
     * Tests OpenEMR API Example Endpoint After Getting Auth for the REST and FHIR APIs
     *  Then test revoking user
     */
    #[Test]
    public function testApiAuthExampleUseThenRevoke()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $id_token = json_decode($actualValue->getBody())->id_token;
        $this->assertGreaterThan(10, strlen($id_token));

        $actualResponse = $this->testClient->cleanupRevokeAuth();
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->assertEquals("You have been signed out. Thank you.", $actualResponse->getBody());

        $actualResponse = $this->testClient->cleanupRevokeAuth();
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->assertEquals("You are currently not signed in.", $actualResponse->getBody());

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(400, $actualResponse->getStatusCode());

        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    /**
     * Tests OpenEMR API Example Endpoint with Invalid Site After Getting Auth for the REST and FHIR APIs
     */
    #[Test]
    public function testApiAuthExampleUseBadSite()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT_INVALID_SITE);
        $this->assertEquals(400, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    /**
     * Tests OpenEMR API Example Endpoint After Getting Auth With Bad Bearer Token for the REST and FHIR APIs
     */
    #[Test]
    public function testApiAuthExampleUseBadToken()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);

        $this->testClient->setBearer(ApiTestClient::BOGUS_ACCESS_TOKEN);
        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(401, $actualResponse->getStatusCode());
    }

    /**
     * Tests OpenEMR API Example Endpoint After Getting Auth With Empty Bearer Token for the REST and FHIR APIs
     */
    #[Test]
    public function testApiAuthExampleUseEmptyToken()
    {
        $actualValue = $this->setAuthToken();
        $this->assertEquals(200, $actualValue->getStatusCode());
        $this->assertGreaterThan(10, strlen($this->testClient->getIdToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getAccessToken()));
        $this->assertGreaterThan(10, strlen($this->testClient->getRefreshToken()));

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);

        $actualResponse = $this->get(self::EXAMPLE_API_ENDPOINT);
        $this->assertEquals(401, $actualResponse->getStatusCode());
    }

    /**
     * when an auth token is not present
     */
    #[Test]
    public function testRemoveAuthTokenNoToken()
    {
        $this->testClient->removeAuthToken();
        $actualHeaders = $this->testClient->getConfig("headers");
        $this->assertArrayNotHasKey("Authorization", $actualHeaders);
    }

    #[Test]
    public function testApiAuthPublicClientDoesNotReturnRefreshToken()
    {
        $actualValue = $this->testClient->setAuthToken(ApiTestClient::OPENEMR_AUTH_ENDPOINT, [], 'public', $this->getCoverageId());
        $this->assertEquals(200, $actualValue->getStatusCode(), "public client authorization should return valid status code");
        $this->assertNull($this->testClient->getRefreshToken(), "Refresh token should be empty for public client");
        $this->assertNotNull($this->testClient->getAccessToken(), "Access token should be populated");
        $this->assertNotNull($this->testClient->getIdToken(), "Id token should be populated");
    }
}
