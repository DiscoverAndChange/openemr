<?php

namespace OpenEMR\Tests\Api;

use OpenEMR\Tools\Coverage\CoverageHelper;
use PHPUnit\Framework\TestCase;

class ApiTestCase extends TestCase
{
    private string $baseUrl;

    /**
     * @var ApiTestClient
     * TODO: we may want to switch this to private after refactoring tests to use the new ApiTestClient
     */
    protected $testClient;

    protected function setUp(): void
    {
        $baseUrl = getenv("OPENEMR_BASE_URL_API", true) ?: "https://localhost";
        $this->testClient = new ApiTestClient($baseUrl, false);
        $this->baseUrl = $baseUrl;
    }

    protected function tearDown(): void
    {
        $this->testClient->cleanupRevokeAuth();
        $this->testClient->cleanupClient();
    }
    public function setAuthToken() {
        return $this->testClient->setAuthToken(ApiTestClient::OPENEMR_AUTH_ENDPOINT, array(), 'private', $this->getCoverageId());
    }

    public function get(string $url, array $params = []) {
        return $this->testClient->get($url, $params, $this->getCoverageId());
    }

    public function post(string $url, array $data, bool $isJson = true) {
        $coverageId = $this->getCoverageId();
        return $this->testClient->post($url, $data, $isJson, $coverageId);
    }

    public function put(string $url, string $id, array $data) {
        $coverageId = $this->getCoverageId();
        return $this->testClient->put($url, $id, $data, $coverageId);
    }

    public function getOne(string $url, string $id) {
        return $this->testClient->getOne($url, $id, $this->getCoverageId());
    }

    protected function getCoverageId() {
        return CoverageHelper::resolveCoverageId(static::class, $this->dataName());
    }

}
