<?php
/*
 * FhirCoverageServiceTest.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2025 Stephen Nielson <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Tests\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCoverage;
use OpenEMR\Services\FHIR\FhirCodeSystemConstants;
use OpenEMR\Services\FHIR\FhirCoverageService;
use OpenEMR\Services\InsuranceService;
use PHPUnit\Framework\TestCase;

class FhirCoverageServiceTest extends TestCase {

    const HTTP_CTS_NLM_NIH_GOV_FHIR_VALUE_SET_2_16_840_1_114222_4_11_3591 = 'http://cts.nlm.nih.gov/fhir/ValueSet/2.16.840.1.114222.4.11.3591';

    public function testParseOpenEMRRecord()
    {
        $record = [
            // insurance_data fields
            'id' => 12345,
            'uuid' => 'coverage-uuid-12345',
            'type' => 'primary',
            'provider' => 'Test Insurance Co.',
            'plan_name' => 'Test Plan',
            'policy_number' => 'POL123456789',
            'group_number' => 'GRP123456789',
            'subscriber_lname' => 'Doe',
            'subscriber_fname' => 'John',
            'subscriber_mname' => 'A',
            'subscriber_ss' => '123-45-6789',
            'subscriber_dob' => '1980-01-01',
            'subscriber_street' => '123 Main St',
            'subscriber_postal_code' => '12345',
            'subscriber_city' => 'Anytown',
            'subscriber_state' => 'CA',
            'subscriber_country' => 'US',
            'subscriber_phone' => '555-123-4567',
            'subscriber_employer' => 'Test Employer',
            'subscriber_employer_street' => '456 Employer St',
            'subscriber_employer_postal_code' => '67890',
            'subscriber_employer_state' => 'TX',
            'subscriber_employer_country' => 'US',
            'subscriber_employer_city' => 'Othertown',
            'copay' => 20.00,
            'date' => '2025-01-01T00:00:00Z',
            'pid' => 1,
            'subscriber_sex' => 'M',
            'subscriber_relationship' => 'self',
            'accept_assignment', 'TRUE',
            'policy_type' => '',

            // additional fields
            'puuid' => 'patient-uuid-12345',
            'insureruuid' => 'insurance-uuid-12345',
            'insurer_name' => 'Test Insurance Co.',
            'insurer_source_of_payment_id' => 1,
            'insurer_source_of_payment_description' => 'Test Insurance Co.',
            'insurance_company' => 'Test Insurance Co.',
        ];
        $coverageService = new FhirCoverageService();
        $coverageResource = $coverageService->parseOpenEMRRecord($record);
        $this->assertInstanceOf(FhirCoverage::class, $coverageResource);

        // Validate the FHIR Coverage resource fields
        // US Core 6.1.0 Profile required items
        $profiles = $coverageResource->getMeta()->getProfile();
        $profileValues = array_map(fn($canonical) => $canonical->getValue(), $profiles);
        $this->assertContains(FhirCoverageService::PROFILE_US_CORE . "|6.1.0", $profileValues, "Expected FHIR Coverage to have US Core 6.1.0 profile.");
        $this->assertEquals('coverage-uuid-12345', $coverageResource->getId(), "Expected FHIR Coverage ID to match OpenEMR coverage UUID.");

        // identifier:memberId
        $identifiers = $coverageResource->getIdentifier();
        $this->assertCount(1, $identifiers, "Expected one identifier for the coverage.");
        $this->assertEquals('POL123456789', $identifiers[0]->getValue()->getValue(), "Expected identifier value to match policy number.");
        $this->assertEquals('MB', $identifiers[0]->getType()->getCoding()[0]->getCode(), "Expected identifier type to be 'MB' (member ID).");
        $this->assertEquals('http://terminology.hl7.org/CodeSystem/v2-0203', $identifiers[0]->getType()->getCoding()[0]->getSystem(), "Expected identifier type system to match HL7 v2-0203.");

        // status
        $this->assertEquals('active', $coverageResource->getStatus()->getValue(), "Expected coverage status to be 'active'.");

        // type
        $type = $coverageResource->getType();
        $this->assertNotNull($type, "Expected coverage type to be set.");
        $this->assertEquals(FhirCodeSystemConstants::VSAC_PayerType_SOP, $type->getCoding()[0]->getSystem(), "Expected value set to match Payer SOP value set");
        $this->assertEquals($record['insurer_source_of_payment_id'], $type->getCoding()[0]->getCode(), "Expected coverage type code to match source of payment ID.");
        $this->assertEquals($record['insurer_source_of_payment_description'], $type->getCoding()[0]->getDisplay(), "Expected coverage type display to match source of payment name.");

        // subscriberId
        $this->assertEquals($record['policy_number'], $coverageResource->getSubscriberId()->getValue(), "Expected subscriber ID to match subscriber's insurance policy number.");

        // beneficiary
        $this->assertNotNull($coverageResource->getBeneficiary(), "Expected beneficiary reference to be set.");
        $this->assertEquals('Patient/patient-uuid-12345', $coverageResource->getBeneficiary()->getReference(), "Expected beneficiary reference to match OpenEMR patient UUID.");

        // relationship
        $relationship = $coverageResource->getRelationship();
        $this->assertNotNull($relationship, "Expected relationship to be set.");
        $this->assertEquals('self', $relationship->getCoding()[0]->getCode(), "Expected relationship code to be 'self'.");
        $this->assertEquals(FhirCodeSystemConstants::HL7_SUBSCRIBER_RELATIONSHIP, $relationship->getCoding()[0]->getSystem(), "Expected relationship systems.");
    }

    public function testParseOpenEMRRecordWithComplexData() {
        // TODO: @adunsulag need to flesh out many more tests to ensure the various fields are parsed correctly
        // for now we have a thin thread
        $this->markTestIncomplete("Test is not complete");
    }

    public function testGetProfileUris() {
        $coverageService = new FhirCoverageService();
        $uris = $coverageService->getProfileUris();
        $this->assertIsArray($uris, "Expected profile URIs to be an array.");
        $this->assertContains(FhirCoverageService::PROFILE_US_CORE . "|6.1.0", $uris, "Expected US Core 6.1.0 profile URI to be present.");
        $this->assertContains(FhirCoverageService::PROFILE_US_CORE . "|7.0.0", $uris, "Expected US Core 7.0.0 profile URI to be present.");
        $this->assertContains(FhirCoverageService::PROFILE_US_CORE . "|8.0.0", $uris, "Expected US Core 8.0.0 profile URI to be present.");
    }
}
