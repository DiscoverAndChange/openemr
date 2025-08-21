<?php

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRElement\FHIRCanonical;
use OpenEMR\FHIR\R4\FHIRElement\FHIRFinancialResourceStatusCodes;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRIdentifier;
use OpenEMR\FHIR\R4\FHIRElement\FHIRInstant;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCoverage;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\FHIR\R4\FHIRElement\FHIRString;
use OpenEMR\FHIR\R4\FHIRResource\FHIRCoverage\FHIRCoverageClass;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\InsuranceService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

/**
 * FHIR Coverage Service
 *
 * @package            OpenEMR
 * @link               http://www.open-emr.org
 * @author             Vishnu Yarmaneni <vardhanvishnu@gmail.com>
 * @copyright          Copyright (c) 2021 Vishnu Yarmaneni <vardhanvishnu@gmail.com>
 * @license            https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

class FhirCoverageService extends FhirServiceBase implements IPatientCompartmentResourceService, IFhirExportableResourceService, IResourceUSCIGProfileService
{
    use FhirServiceBaseEmptyTrait;
    use FhirBulkExportDomainResourceTrait;
    use BulkExportSupportAllOperationsTrait;

    const PROFILE_US_CORE = "http://hl7.org/fhir/us/core/StructureDefinition/us-core-coverage";

    /**
     * @var InsuranceService
     */
    private InsuranceService $insuranceService;

    public function __construct()
    {
        parent::__construct();
    }

    public function getInsuranceService(): InsuranceService
    {
        if (!isset($this->insuranceService)) {
            $this->insuranceService = new InsuranceService();
        }
        return $this->insuranceService;
    }

    public function setInsuranceService(InsuranceService $coverageService): void
    {
        $this->insuranceService = $coverageService;
    }

    /**
     * Returns an array mapping FHIR Coverage Resource search parameters to OpenEMR Condition search parameters
     *
     * @return array The search parameters
     */
    protected function loadSearchParameters(): array
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'payor' => new FhirSearchParameterDefinition('payor', SearchFieldType::TOKEN, ['provider']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['date']);
    }

    /**
     * Parses an OpenEMR Insurance record, returning the equivalent FHIR Coverage Resource
     *
     * @param  array   $dataRecord The source OpenEMR data record
     * @param  boolean $encode     Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRCoverage|string The FHIR Coverage Resource or JSON string representation of the resource
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false): FHIRDomainResource|string
    {
        /**
         * identifier (array of Identifier) 0..*
         *      memberId (string) 0..1 [type=Coding, system=http://terminology.hl7.org/CodeSystem/v2-0203, code=MB]
         * status (code)
         * type (CodeableConcept) 0..1
         * subscriberId (string) 0..1
         * beneficiary (Reference) 1..1
         * relationship (CodeableConcept) 1..1
         * period (Period) 0..1
         * payor (Reference) 1..1
         * class:group (array of CoverageClass) 0..1
         *      type (CodeableConcept) 1..1
         *          coding (array of Coding) 1..*
         *              system=http://terminology.hl7.org/CodeSystem/coverage-class (string) 1..1
         *              code=group (string) 1..1
         *      value (string) 1..1 (Group number)
         *      name (string) 1..1  (Group name)
         * class:plan (array of CoveragePlan) 0..1
         *      type (CodeableConcept) 1..1
         *          coding (array of Coding) 1..*
         *              system=http://terminology.hl7.org/CodeSystem/coverage-class (string) 1..1
         *              code=plan (string) 1..1
         *          value (string) 1..1 (Plan number)
         *      name (string) 1..1  (Plan name)
         */

        $coverageResource = new FHIRCoverage();
        $meta = new FHIRMeta();
        $id = new FhirId();
        $id->setValue('1');
        $meta->setVersionId($id);
        if (!empty($dataRecord['date'])) {
            $meta->setLastUpdated(new FhirInstant(UtilsService::getLocalDateAsUTC($dataRecord['date'])));
        } else {
            $meta->setLastUpdated(new FhirInstant(UtilsService::getDateFormattedAsUTC()));
        }
        $coverageResource->setMeta($meta);
        // all the below are the required fields for US Core 6.1.0
        $meta->addProfile(new FHIRCanonical(self::PROFILE_US_CORE . "|6.1.0"));

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $coverageResource->setId($id);

        $identifier = new FHIRIdentifier();
        /**
         * https://terminology.hl7.org/6.5.0/CodeSystem-v2-0203.html
         * MB	Member Number	An identifier for the insured of an insurance policy (this insured always has a subscriber), usually assigned by the insurance carrier.
         */
        $identifier->setType(UtilsService::createCodeableConcept([
            'MB' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                'code' => 'MB',
                'description' => 'Member ID'
            ]
        ]));
        // member id and subscribe id are the same in OpenEMR
        $identifier->setValue(new FHIRString($dataRecord['policy_number']));
        $coverageResource->addIdentifier($identifier);

        // subscriberId (string) 0..1
        $coverageResource->setSubscriberId(new FHIRString($dataRecord['policy_number']));

        // TODO: need to set logic for active/inactive status
        // if we have a date_end > NOW(), then we assume the coverage is cancelled
        //Currently Setting status to active - Change after status logic is confirmed
        $status = new FHIRFinancialResourceStatusCodes('active');
        $coverageResource->setStatus($status);

        // * type (CodeableConcept) 0..1
        if (isset($dataRecord['insurer_source_of_payment_id'])) {
            $coverageResource->setType(UtilsService::createCodeableConcept([
                $dataRecord['insurer_source_of_payment_id'] => [
                    // PayerType (CodeSystem=SOP)
                    // viewable from https://vsac.nlm.nih.gov/valueset/2.16.840.1.114222.4.11.3591/expansion
                    'system' => FhirCodeSystemConstants::VSAC_PayerType_SOP,
                    'code' => $dataRecord['insurer_source_of_payment_id'],
                    'description' => $dataRecord['insurer_source_of_payment_description'] ?? $dataRecord['insurer_source_of_payment_id']
                ]
            ]));
        }

        if (isset($dataRecord['puuid'])) {
            $coverageResource->setBeneficiary(UtilsService::createRelativeReference("Patient", $dataRecord['puuid']));
        }

        if (isset($dataRecord['subscriber_relationship'])) {
            $coverageResource->setRelationship(UtilsService::createCodeableConcept([
                $dataRecord['subscriber_relationship'] => [
                    'code' => $dataRecord['subscriber_relationship']
                    ,'system' => FhirCodeSystemConstants::HL7_SUBSCRIBER_RELATIONSHIP
                ]
            ]));
        } else {
            $coverageResource->setRelationship(UtilsService::createDataAbsentUnknownCodeableConcept());
        }

        if (!empty($dataRecord['date'])) {
            $period = UtilsService::createPeriod($dataRecord['date'], $dataRecord['date_end'] ?? null);
            $coverageResource->setPeriod($period);
        }

        // payor... This will be the insurance company, the patient, or the guarantor
        $coverageResource->addPayor($this->getPayorReference($dataRecord));

        $fhirGroupCoverageClass = new FHIRCoverageClass();
        $fhirGroupCoverageClass->setType(UtilsService::createCodeableConcept([
            'system' => 'http://terminology.hl7.org/CodeSystem/coverage-class',
            'code' => 'group',
            'display' => 'Group'
        ]));
        if (!empty($dataRecord['group_number'])) {
            $fhirGroupCoverageClass->setValue(new FHIRString($dataRecord['group_number']));
            // if we have no group name, we will use the group number as the name
            $fhirGroupCoverageClass->setName(new FHIRString($dataRecord['group_name'] ?? $dataRecord['group_number']));

        } else {
            $fhirGroupCoverageClass->addExtension(UtilsService::createDataMissingExtension());
        }
        $coverageResource->addClass($fhirGroupCoverageClass);

        $fhirPlanCoverageClass = new FHIRCoverageClass();
        $fhirPlanCoverageClass->setType(UtilsService::createCodeableConcept([
            'system' => 'http://terminology.hl7.org/CodeSystem/coverage-class',
            'code' => 'plan',
            'display' => 'Plan'
        ]));
        // both required fields in order to populate this value
        if (empty($dataRecord['plan_name']) || empty($dataRecord['group_number'])) {
            $fhirPlanCoverageClass->addExtension(UtilsService::createDataMissingExtension());
        } else {
            // Plan Name -> plan_name
            $fhirPlanCoverageClass->setName(new FHIRString($dataRecord['plan_name']));
            // Plan Number -> group_number
            $fhirPlanCoverageClass->setValue(new FHIRString($dataRecord['group_number']));

            $coverageResource->addClass($fhirPlanCoverageClass);
        }
        $coverageResource->addClass($fhirPlanCoverageClass);

        // no new additions to US Core 7, and US Core 8... just add both profiles
        $coverageResource->getMeta()->addProfile(new FHIRCanonical(self::PROFILE_US_CORE . "|7.0.0"));
        $coverageResource->getMeta()->addProfile(new FHIRCanonical(self::PROFILE_US_CORE . "|8.0.0"));

        if ($encode) {
            return json_encode($coverageResource);
        } else {
            return $coverageResource;
        }
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param  array $openEMRSearchParameters OpenEMR search fields
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters): ProcessingResult
    {
        return $this->getInsuranceService()->search($openEMRSearchParameters);
    }

    public function getPatientContextSearchField(): FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('patient', SearchFieldType::REFERENCE, [new ServiceField('puuid', ServiceField::TYPE_UUID)]);
    }

    private function getPayorReference(array $dataRecord): FHIRReference
    {
        // if insureruuid is empty, we will assume the payor is the patient
        if (empty($dataRecord['insureruuid'])) {
            return UtilsService::createRelativeReference('Patient', $dataRecord['puuid']);
        } else {
            // otherwise, we will assume the payor is the insurance company (or an organization that was entered in as a Provider)
            // we don't support RelatedPerson as a payor in this context even though the FHIR spec allows it
            return UtilsService::createRelativeReference('Organization', $dataRecord['insureruuid']);
        }
    }

    /**
     * @return string[] Returns the URIs of the FHIR profiles supported by this service.
     */
    public function getProfileURIs() : array {
        return [
            self::PROFILE_US_CORE . "|6.1.0",
            self::PROFILE_US_CORE . "|7.0.0",
            self::PROFILE_US_CORE . "|8.0.0"
        ];
    }
}
