<?php

/**
 * FHIR API Routes
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2018 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\RestControllers\Config\RestConfig;
use OpenEMR\RestControllers\FHIR\FhirCoverageRestController;

use OpenEMR\Services\FHIR\FhirCoverageService;
use OpenEMR\Services\FHIR\FhirCodeSystemConstants;

// Note that the fhir route includes both user role and patient role
//  (there is a mechanism in place to ensure patient role is binded
//   to only see the data of the one patient)
return array(
    /**
     *  @OA\Get(
     *      path="/fhir/Coverage",
     *      description="Returns a list of Coverage resources.",
     *      tags={"fhir"},
     *      @OA\Parameter(
     *          name="_id",
     *          in="query",
     *          description="The uuid for the Coverage resource.",
     *          required=false,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="_lastUpdated",
     *          in="query",
     *          description="Allows filtering resources by the _lastUpdated field. A FHIR Instant value in the format YYYY-MM-DDThh:mm:ss.sss+zz:zz.  See FHIR date/time modifiers for filtering options (ge,gt,le, etc)",
     *          required=false,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="patient",
     *          in="query",
     *          description="The uuid for the patient.",
     *          required=false,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="payor",
     *          in="query",
     *          description="The payor of the Coverage resource.",
     *          required=false,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Standard Response",
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="json object",
     *                      description="FHIR Json object.",
     *                      type="object"
     *                  ),
     *                  example={
     *                      "meta": {
     *                          "lastUpdated": "2021-09-14T09:13:51"
     *                      },
     *                      "resourceType": "Bundle",
     *                      "type": "collection",
     *                      "total": 0,
     *                      "link": {
     *                          {
     *                              "relation": "self",
     *                              "url": "https://localhost:9300/apis/default/fhir/Coverage"
     *                          }
     *                      }
     *                  }
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response="400",
     *          ref="#/components/responses/badrequest"
     *      ),
     *      @OA\Response(
     *          response="401",
     *          ref="#/components/responses/unauthorized"
     *      ),
     *      security={{"openemr_auth":{}}}
     *  )
     */
    "GET /fhir/Coverage" => function (HttpRestRequest $request, OEGlobalsBag $globalsBag) {
        $fhirCoverageRestController = new FhirCoverageRestController($globalsBag);
        $fhirCoverageRestController->setResourceProfile(FhirCoverageService::PROFILE_US_CORE
            . "|" . FhirCodeSystemConstants::US_CORE_VERSION_8_0_0);

        if ($request->isPatientRequest()) {
            // only allow access to data of binded patient
            $return = $fhirCoverageRestController->getAll($request->getQueryParams(), $request->getPatientUUIDString());
        } else {
            RestConfig::request_authorization_check($request, "admin", "super");
            $return = $fhirCoverageRestController->getAll($request->getQueryParams());
        }

        return $return;
    },

);
