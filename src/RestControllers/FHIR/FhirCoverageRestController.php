<?php

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCoverage;
use OpenEMR\Services\FHIR\FhirCoverageService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;

/**
 * FHIR Organization Service
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class FhirCoverageRestController
{
    private OEGlobalsBag $globalsBag;

    private string $fhirProfile = '';

    public function __construct(OEGlobalsBag $globalsBag)
    {
        $this->globalsBag = $globalsBag;
    }

    /**
     * Queries for FHIR Coverage resource using various search parameters.
     * Search parameters include:
     * - beneficiary
     * - patient
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $coverageService = new FhirCoverageService();
        $processingResult = $coverageService->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            if ($searchResult instanceof FHIRCoverage) {
                $bundleEntry = [
                    'fullUrl' =>  $this->globalsBag->get('site_addr_oath') . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                    'resource' => $searchResult
                ];
                $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
                array_push($bundleEntries, $fhirBundleEntry);
            }
        }
        $fhirBundleService = new FhirResourcesService();
        $bundleSearchResult = $fhirBundleService->createBundle('Coverage', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }


    /**
     * Queries for a single FHIR Coverage resource by FHIR id
     * @param $fhirId The FHIR Coverage resource id (uuid)
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId, $puuidBind = null)
    {
        $coverageService = new FhirCoverageService();
        $processingResult = $coverageService->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Used for specifying the FHIR profile the resource should conform to.
     * @param string $string
     * @return void
     */
    public function setResourceProfile(string $string): void
    {
        $this->fhirProfile = $string;
    }
}
