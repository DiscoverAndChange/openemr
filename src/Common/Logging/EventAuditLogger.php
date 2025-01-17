<?php

/**
 * Class to log audited events - must be high performance
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Kyle Wiering <kyle@softwareadvice.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Kyle Wiering <kyle@softwareadvice.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Logging;

use DateTime;
use OpenEMR\Common\Crypto\CryptoGen;
use Waryway\PhpTraitsLibrary\Singleton;

class EventAuditLogger
{
    use Singleton;

    /**
     * @var CryptoGen
     */
    private $cryptoGen;

    /**
     * @var boolean
     */
    private $breakglassUser;

    /**
     * Event action codes indicate whether the event is read/write.
     * C = create, R = read, U = update, D = delete, E = execute
     */
    private const EVENT_ACTION_CODE_EXECUTE = 'E';
    private const EVENT_ACTION_CODE_CREATE = 'C';
    private const EVENT_ACTION_CODE_INSERT = 'C';
    private const EVENT_ACTION_CODE_SELECT = 'R';
    private const EVENT_ACTION_CODE_UPDATE = 'U';
    private const EVENT_ACTION_CODE_DELETE = 'D';

    /**
     * Keep track of the table mapping in a class constant to prevent reloading the data each time the method is called.
     *
     * @var array
     */
    private const LOG_TABLES = [
        "billing" => "patient-record",
        "claims" => "patient-record",
        "employer_data" => "patient-record",
        "forms" => "patient-record",
        "form_encounter" => "patient-record",
        "form_dictation" => "patient-record",
        "form_misc_billing_options" => "patient-record",
        "form_reviewofs" => "patient-record",
        "form_ros" => "patient-record",
        "form_soap" => "patient-record",
        "form_vitals" => "patient-record",
        "history_data" => "patient-record",
        "immunizations" => "patient-record",
        "insurance_data" => "patient-record",
        "issue_encounter" => "patient-record",
        "lists" => "patient-record",
        "patient_data" => "patient-record",
        "payments" => "patient-record",
        "pnotes" => "patient-record",
        "onotes" => "patient-record",
        "prescriptions" => "order",
        "transactions" => "patient-record",
        "amendments" => "patient-record",
        "amendments_history" => "patient-record",
        "facility" => "security-administration",
        "pharmacies" => "security-administration",
        "addresses" => "security-administration",
        "phone_numbers" => "security-administration",
        "x12_partners" => "security-administration",
        "insurance_companies" => "security-administration",
        "codes" => "security-administration",
        "registry" => "security-administration",
        "users" => "security-administration",
        "groups" => "security-administration",
        "openemr_postcalendar_events" => "scheduling",
        "openemr_postcalendar_categories" => "security-administration",
        "openemr_postcalendar_limits" => "security-administration",
        "openemr_postcalendar_topics" => "security-administration",
        "gacl_acl" => "security-administration",
        "gacl_acl_sections" => "security-administration",
        "gacl_acl_seq" => "security-administration",
        "gacl_aco" => "security-administration",
        "gacl_aco_map" => "security-administration",
        "gacl_aco_sections" => "security-administration",
        "gacl_aco_sections_seq" => "security-administration",
        "gacl_aco_seq" => "security-administration",
        "gacl_aro" => "security-administration",
        "gacl_aro_groups" => "security-administration",
        "gacl_aro_groups_id_seq" => "security-administration",
        "gacl_aro_groups_map" => "security-administration",
        "gacl_aro_map" => "security-administration",
        "gacl_aro_sections" => "security-administration",
        "gacl_aro_sections_seq" => "security-administration",
        "gacl_aro_seq" => "security-administration",
        "gacl_axo" => "security-administration",
        "gacl_axo_groups" => "security-administration",
        "gacl_axo_groups_map" => "security-administration",
        "gacl_axo_map" => "security-administration",
        "gacl_axo_sections" => "security-administration",
        "gacl_groups_aro_map" => "security-administration",
        "gacl_groups_axo_map" => "security-administration",
        "gacl_phpgacl" => "security-administration",
        "procedure_order" => "lab-order",
        "procedure_order_code" => "lab-order",
        "procedure_report" => "lab-results",
        "procedure_result" => "lab-results"
    ];

    private const RFC3881_MSG_PRIMARY_TEMPLATE = <<<MSG
<13>%s %s
<?xml version="1.0" encoding="ASCII"?>
 <AuditMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="healthcare-security-audit.xsd">
  <EventIdentification EventActionCode="%s" EventDateTime="%s" EventOutcomeIndicator="%s">
   <EventID code="eventIDcode" displayName="%s" codeSystemName="DCM" />
  </EventIdentification>
  <ActiveParticipant UserID="%s" UserIsRequestor="true" NetworkAccessPointID="%s" NetworkAccessPointTypeCode="2" >
   <RoleIDCode code="110153" displayName="Source" codeSystemName="DCM" />
  </ActiveParticipant>
  <ActiveParticipant UserID="%s" UserIsRequestor="false" NetworkAccessPointID="%s" NetworkAccessPointTypeCode="2" >
   <RoleIDCode code="110152" displayName="Destination" codeSystemName="DCM" />
  </ActiveParticipant>
  <AuditSourceIdentification AuditSourceID="%s" />
  <ParticipantObjectIdentification ParticipantObjectID="%s" ParticipantObjectTypeCode="1" ParticipantObjectTypeCodeRole="6" >
   <ParticipantObjectIDTypeCode code="11" displayName="User Identifier" codeSystemName="RFC-3881" />
  </ParticipantObjectIdentification>
  %s
 </AuditMessage>
MSG;

    private const RFC3881_MSG_PATIENT_TEMPLATE = <<<MSG
<ParticipantObjectIdentification ParticipantObjectID="%s" ParticipantObjectTypeCode="1" ParticipantObjectTypeCodeRole="1">
 <ParticipantObjectIDTypeCode code="2" displayName="Patient Number" codeSystemName="RFC-3881" />
</ParticipantObjectIdentification>
MSG;

    /**
     * @param $event
     * @param $user
     * @param $groupname
     * @param $success
     * @param string    $comments
     * @param null      $patient_id
     * @param string    $log_from
     * @param string    $menu_item
     * @param int       $ccda_doc_id
     */
    public function newEvent(
        $event,
        $user,
        $groupname,
        $success,
        $comments = "",
        $patient_id = null,
        $log_from = 'open-emr',
        $menu_item = 'dashboard',
        $ccda_doc_id = 0
    ) {
        $category = $event;
        // Special case delete for lists table
        if ($event == 'delete') {
            $category = $this->eventCategoryFinder($comments, $event, '');
        }

        if ($log_from == 'patient-portal') {
            $sqlMenuItems = "SELECT * FROM patient_portal_menu";

            $resMenuItems = sqlStatement($sqlMenuItems);
            for ($iter = 0; $rowMenuItem = sqlFetchArray($resMenuItems); $iter++) {
                $menuItems[$rowMenuItem['patient_portal_menu_id']] = $rowMenuItem['menu_name'];
            }

            $menuItemId = array_search($menu_item, $menuItems);

            $this->recordLogItem($success, $event, $user, $groupname, $comments, $patient_id, 'Patient Portal', $log_from, $menuItemId, $ccda_doc_id);
        } else {
            $this->recordLogItem($success, $event, $user, $groupname, $comments, $patient_id, $category);
        }
    }

    /******************
     * Get records from the LOG and Extended_Log table
     * using the optional parameters:
     *   date : a specific date  (defaults to today)
     *   user : a specific user  (defaults to none)
     *   cols : gather specific columns  (defaults to date,event,user,groupname,comments)
     *   sortby : sort the results by  (defaults to none)
     * RETURNS:
     *   array of results
     ******************/
    public function getEvents($params)
    {
        // parse the parameters
        $cols = "DISTINCT l.`date`, l.`event`, l.`category`, l.`user`, l.`groupname`, l.`patient_id`, l.`success`, l.`comments`, l.`user_notes`, l.`crt_user`, l.`log_from`, l.`menu_item_id`, l.`ccda_doc_id`, l.`id`,
                 el.`encrypt`, el.`checksum`, el.`checksum_api`, el.`version`, el.`log_id` as `log_id_hash`,
                 al.`log_id` as log_id_api, al.`user_id`, al.`patient_id` as patient_id_api, al.`ip_address`, al.`method`, al.`request`, al.`request_url`, al.`request_body`, al.`response`, al.`created_time` ";
        if (isset($params['cols']) && $params['cols'] != "") {
            $cols = $params['cols'];
        }

        $date1 = date("Y-m-d H:i:s", time());
        if (isset($params['sdate']) && $params['sdate'] != "") {
            $date1 = $params['sdate'];
        }

        $date2 = date("Y-m-d H:i:s", time());
        if (isset($params['edate']) && $params['edate'] != "") {
            $date2 = $params['edate'];
        }

        $user = "";
        if (isset($params['user']) && $params['user'] != "") {
            $user = $params['user'];
        }

        //VicarePlus :: For Generating log with patient id.
        $patient = "";
        if (isset($params['patient']) && $params['patient'] != "") {
            $patient = $params['patient'];
        }

        $sortby = "";
        if (isset($params['sortby']) && $params['sortby'] != "") {
            $sortby = $params['sortby'];
        }

        $levent = "";
        if (isset($params['levent']) && $params['levent'] != "") {
            $levent = $params['levent'];
        }

        $tevent = "";
        if (isset($params['tevent']) && $params['tevent'] != "") {
            $tevent = $params['tevent'];
        }

        $direction = 'asc';
        if (isset($params['direction']) && $params['direction'] != "") {
            $direction = $params['direction'];
        }

        $event = "";
        if (isset($params['event']) && $params['event'] != "") {
            $event = $params['event'];
        }

        if ($event != "") {
            if ($sortby == "comments") {
                $sortby = "description";
            }

            if ($sortby == "groupname") {
                $sortby = ""; //VicarePlus :: since there is no groupname in extended_log
            }

            if ($sortby == "success") {
                $sortby = "";   //VicarePlus :: since there is no success field in extended_log
            }

            if ($sortby == "category") {
                $sortby = "";  //VicarePlus :: since there is no category field in extended_log
            }

            $sqlBindArray = array();
            $columns = "DISTINCT date, event, user, recipient,patient_id,description";
            $sql = "SELECT $columns FROM extended_log WHERE date >= ? AND date <= ?";
            array_push($sqlBindArray, $date1, $date2);

            if ($user != "") {
                $sql .= " AND user LIKE ?";
                array_push($sqlBindArray, $user);
            }

            if ($patient != "") {
                $sql .= " AND patient_id LIKE ?";
                array_push($sqlBindArray, $patient);
            }

            if ($levent != "") {
                $sql .= " AND event LIKE ?";
                array_push($sqlBindArray, $levent . "%");
            }

            if ($sortby != "") {
                $sql .= " ORDER BY " . escape_sql_column_name($sortby, array('extended_log')) . " DESC"; // descending order
            }

            $sql .= " LIMIT 5000";
        } else {
            // do the query
            $sqlBindArray = array();
            $sql = "SELECT $cols FROM `log_comment_encrypt` as el " .
                "LEFT OUTER JOIN `log` as l ON el.`log_id` = l.`id` " .
                "LEFT OUTER JOIN `api_log` as al ON el.`log_id` = al.`log_id` " .
                "WHERE (l.`date` IS NULL OR (l.`date` >= ? AND l.`date` <= ?))";
            array_push($sqlBindArray, $date1, $date2);

            if ($user != "") {
                $sql .= " AND l.`user` LIKE ?";
                array_push($sqlBindArray, $user);
            }

            if ($patient != "") {
                $sql .= " AND l.`patient_id` LIKE ?";
                array_push($sqlBindArray, $patient);
            }

            if ($levent != "") {
                $sql .= " AND l.`event` LIKE ?";
                array_push($sqlBindArray, $levent . "%");
            }

            if ($tevent != "") {
                $sql .= " AND l.`event` LIKE ?";
                array_push($sqlBindArray, "%" . $tevent);
            }

            if ($sortby != "") {
                $sql .= " ORDER BY `" . escape_sql_column_name($sortby, array('log')) . "`  " . escape_sort_order($direction); // descending order
            } else {
                $sql .= " ORDER BY el.`log_id` DESC";
            }

            $sql .= " LIMIT 5000";
        }

        return sqlStatement($sql, $sqlBindArray);
    }

    /**
     * Event action codes indicate whether the event is read/write.
     * C = create, R = read, U = update, D = delete, E = execute
     *
     * @param  $event
     * @return string
     */
    private function determineRFC3881EventActionCode($event)
    {
        switch (substr($event, -7)) {
            case '-create':
                return self::EVENT_ACTION_CODE_CREATE;
                break;
            case '-insert':
                return self::EVENT_ACTION_CODE_INSERT;
                break;
            case '-select':
                return self::EVENT_ACTION_CODE_SELECT;
                break;
            case '-update':
                return self::EVENT_ACTION_CODE_UPDATE;
                break;
            case '-delete':
                return self::EVENT_ACTION_CODE_DELETE;
                break;
            default:
                return self::EVENT_ACTION_CODE_EXECUTE;
                break;
        }
    }

    /**
     * The choice of event codes is up to OpenEMR.
     * We're using the same event codes as
     * https://iheprofiles.projects.openhealthtools.org/
     *
     * @param $event
     */
    private function determineRFC3881EventIdDisplayName($event)
    {

        $eventIdDisplayName = $event;

        if (strpos($event, 'patient-record') !== false) {
            $eventIdDisplayName = 'Patient Record';
        } elseif (strpos($event, 'view') !== false) {
            $eventIdDisplayName = 'Patient Record';
        } elseif (strpos($event, 'login') !== false) {
            $eventIdDisplayName = 'Login';
        } elseif (strpos($event, 'logout') !== false) {
            $eventIdDisplayName = 'Logout';
        } elseif (strpos($event, 'scheduling') !== false) {
            $eventIdDisplayName = 'Patient Care Assignment';
        } elseif (strpos($event, 'security-administration') !== false) {
            $eventIdDisplayName = 'Security Administration';
        }

        return $eventIdDisplayName;
    }

    /**
     * Create an XML audit record corresponding to RFC 3881.
     * The parameters passed are the column values (from table 'log')
     * for a single audit record.
     *
     * @param  $user
     * @param  $group
     * @param  $event
     * @param  $patient_id
     * @param  $outcome
     * @param  $comments
     * @return string
     */
    private function createRfc3881Msg($user, $group, $event, $patient_id, $outcome, $comments)
    {
        $eventActionCode = $this->determineRFC3881EventActionCode($event);
        $eventIdDisplayName = $this->determineRFC3881EventIdDisplayName($event);

        $eventDateTime = (new DateTime())->format(DATE_ATOM);

        /* For EventOutcomeIndicator, 0 = success and 4 = minor error */
        $eventOutcome = ($outcome === 1) ? 0 : 4;

        /*
         * Variables used in ActiveParticipant section, which identifies
         * the IP address and application of the source and destination.
         */
        $srcUserID = $_SERVER['SERVER_NAME'] . '|OpenEMR';
        $srcNetwork = $_SERVER['SERVER_ADDR'];
        $destUserID = $GLOBALS['atna_audit_host'];
        $destNetwork = $GLOBALS['atna_audit_host'];

        $patientRecordForMsg = ($eventIdDisplayName == 'Patient Record' && $patient_id != 0) ? sprintf(self::RFC3881_MSG_PATIENT_TEMPLATE, $patient_id) : '';
        /* Add the syslog header  with $eventDateTime and $_SERVER['SERVER_NAME'] */
        return sprintf(self::RFC3881_MSG_PRIMARY_TEMPLATE, $eventDateTime, $_SERVER['SERVER_NAME'], $eventActionCode, $eventDateTime, $eventOutcome, $eventIdDisplayName, $srcUserID, $srcNetwork, $destUserID, $destNetwork, $srcUserID, $user, $patientRecordForMsg);
    }

    /**
     * Create a TLS (SSLv3) connection to the given host/port.
     * $localcert is the path to a PEM file with a client certificate and private key.
     * $cafile is the path to the CA certificate file, for
     *  authenticating the remote machine's certificate.
     * If $cafile is "", the remote machine's certificate is not verified.
     * If $localcert is "", we don't pass a client certificate in the connection.
     *
     * Return a stream resource that can be used with fwrite(), fread(), etc.
     * Returns FALSE on error.
     *
     * @param  $host
     * @param  $port
     * @param  $localcert
     * @param  $cafile
     * @return bool|resource
     */
    private function createTlsConn($host, $port, $localcert, $cafile)
    {
        $sslopts = array();
        if ($cafile !== null && $cafile != "") {
            $sslopts['cafile'] = $cafile;
            $sslopts['verify_peer'] = true;
            $sslopts['verify_depth'] = 10;
        }

        if ($localcert !== null && $localcert != "") {
            $sslopts['local_cert'] = $localcert;
        }

        $opts = array('tls' => $sslopts, 'ssl' => $sslopts);
        $ctx = stream_context_create($opts);
        $timeout = 60;
        $flags = STREAM_CLIENT_CONNECT;

        $olderr = error_reporting(0);
        $conn = stream_socket_client(
            'tls://' . $host . ":" . $port,
            $errno,
            $errstr,
            $timeout,
            $flags,
            $ctx
        );
        error_reporting($olderr);
        return $conn;
    }

    /**
     * This function is used to send audit records to an Audit Repository Server,
     * as described in the Audit Trail and Node Authentication (ATNA) standard.
     * Given the fields in a single audit record:
     * - Create an XML audit message according to RFC 3881, including the RFC5425 syslog header.
     * - Create a TLS connection that performs bi-directions certificate authentication,
     *   according to RFC 5425.
     * - Send the XML message on the TLS connection.
     *
     * @param $user
     * @param $group
     * @param $event
     * @param $patient_id
     * @param $outcome
     * @param $comments
     */
    public function sendAtnaAuditMsg($user, $group, $event, $patient_id, $outcome, $comments)
    {
        /* If no ATNA repository server is configured, return */
        if (empty($GLOBALS['atna_audit_host']) || empty($GLOBALS['enable_atna_audit'])) {
            return;
        }

        $host = $GLOBALS['atna_audit_host'];
        $port = $GLOBALS['atna_audit_port'];
        $localcert = $GLOBALS['atna_audit_localcert'];
        $cacert = $GLOBALS['atna_audit_cacert'];
        $conn = $this->createTlsConn($host, $port, $localcert, $cacert);
        if ($conn !== false) {
            $msg = $this->createRfc3881Msg($user, $group, $event, $patient_id, $outcome, $comments);
            fwrite($conn, $msg);
            fclose($conn);
        }
    }

    /**
     * Add an entry into the audit log table, indicating that an
     * SQL query was performed. $outcome is true if the statement
     * successfully completed.  Determine the event type based on
     * the tables present in the SQL query.
     *
     * @param $statement
     * @param $outcome
     * @param null      $binds
     */
    public function auditSQLEvent($statement, $outcome, $binds = null, $previousValues = null)
    {
        // Set up crypto object that will be used by this singleton class for encryption/decryption (if not set up already)
        if (!isset($this->cryptoGen)) {
            $this->cryptoGen = new CryptoGen();
        }

        $user =  $_SESSION['authUser'] ?? "";
        $statement = trim($statement);
        /* Determine the query type (select, update, insert, delete) */
        $querytype = "select";
        $querytypes = array("select", "update", "insert", "delete","replace");
        foreach ($querytypes as $qtype) {
            if (stripos($statement, $qtype) === 0) {
                $querytype = $qtype;
                break;
            }
        }

        /* If query events are not enabled, don't log them. Exception for "emergency" users. */
        if (($querytype == "select") && !(array_key_exists('audit_events_query', $GLOBALS) && $GLOBALS['audit_events_query'])) {
            if (empty($GLOBALS['gbl_force_log_breakglass']) || !$this->isBreakglassUser($user)) {
                return;
            }
        }

        // Build structured comment with previousValues if provided
        $rawQuery = $this->applyBinds($statement, $binds);
        $commentData = [
            'version' => 5,
            'type' => $querytype,
            'status' => $outcome ? 'success' : 'failure',
            // in order to handle any binary data, we need to base64 encode the raw query
            'raw_query' => base64_encode($rawQuery)
        ];

        // Handle UPDATE specific data
        $table = '';
        if ($querytype == 'update' && isset($previousValues)) {
            if (preg_match('/UPDATE\s+(`?)(\w+)\1\s+SET\s+(.+?)\s+WHERE\s+(.+)/i', $rawQuery, $matches)) {
                $table = $matches[2];
                $setClause = $matches[3];
                $whereClause = $matches[4];

                $commentData['table'] = $table;
                $commentData['where'] = $whereClause; // $this->applyBinds($whereClause, $binds);
                $commentData['before'] = $this->processBinaryData($previousValues);

                // Parse SET values for after state
                $afterValues = $this->parseSetClause($setClause);
                $commentData['after'] = $this->processBinaryData($afterValues);
            }
        } elseif ($querytype == 'select') {
            // Parse SELECT statement
            if (preg_match('/FROM\s+(`?)(\w+)\1/i', $statement, $matches)) {
                $table = $matches[2];
            }
        } elseif ($querytype == 'insert') {
            $insertData = $this->parseInsertQuery($rawQuery);
            if ($insertData !== null) {
                $table = $insertData['table'];
                $commentData['table'] = $table;
                $commentData['before'] = $insertData['before'];
                $commentData['after'] = $insertData['after'];
            }
        } elseif ($querytype == 'delete') {
            // Parse DELETE statement
            if (preg_match('/DELETE\s+FROM\s+(`?)(\w+)\1/i', $statement, $matches)) {
                $table = $matches[2];
            }
        }

        $commentData['table'] = $table;
        if (!empty($binds)) {
            $commentData['bind_parameters'] = $binds;
        }

        // Convert to JSON
//        $comments = json_encode($commentData);
        $comments = $commentData;
//        if (is_array($binds)) {
//            // Need to include the binded variable elements in the logging
//            $processed_binds = "";
//            foreach ($binds as $value_bind) {
//                $processed_binds .= "'" . add_escape_custom($value_bind) . "',";
//            }
//            rtrim($processed_binds, ',');
//
//            if (!empty($processed_binds)) {
//                $comments .= " (" . $processed_binds . ")";
//            }
//        }

        /* Determine the audit event based on the database tables */
        $event = "other";
        $category = "other";

        /* When searching for table names, truncate the SQL statement,
         * removing any WHERE, SET, or VALUE clauses.
         */
//        $truncated_sql = $statement;
//        $truncated_sql = str_replace("\n", " ", $truncated_sql);
//        if ($querytype == "select") {
//            $startwhere = stripos($truncated_sql, " where ");
//            if ($startwhere > 0) {
//                $truncated_sql = substr($truncated_sql, 0, $startwhere);
//            }
//        } else {
//            $startparen = stripos($truncated_sql, "(");
//            $startset = stripos($truncated_sql, " set ");
//            $startvalues = stripos($truncated_sql, " values ");
//
//            if ($startparen > 0) {
//                $truncated_sql = substr($truncated_sql, 0, $startparen);
//            }
//
//            if ($startvalues > 0) {
//                $truncated_sql = substr($truncated_sql, 0, $startvalues);
//            }
//
//            if ($startset > 0) {
//                $truncated_sql = substr($truncated_sql, 0, $startset);
//            }
//        }

        foreach (self::LOG_TABLES as $table => $value) {
            if (strpos($commentData['table'], $table) !== false) {
                $event = $value;
                $category = $this->eventCategoryFinder($commentData['raw_query'], $event, $commentData['table']);
                break;
            } elseif (strpos($commentData['table'], "form_") !== false) {
                $event = "patient-record";
                $category = $this->eventCategoryFinder($commentData['raw_query'], $event, $commentData['table']);
                break;
            }
        }

        /* Avoid filling the audit log with trivial SELECT statements.
         * Skip SELECTs from unknown tables.
         */
        if ($querytype == "select") {
            if ($event == "other") {
                return;
            }
        }

        /* If the event is a patient-record, then note the patient id */
        $pid = 0;
        if ($event == "patient-record") {
            if (array_key_exists('pid', $_SESSION) && $_SESSION['pid'] != '') {
                $pid = $_SESSION['pid'];
            }
        }

        if (empty($GLOBALS["audit_events_{$event}"])) {
            if (!$GLOBALS['gbl_force_log_breakglass'] || !$this->isBreakglassUser($user)) {
                return;
            }
        }

        $event = $event . "-" . $querytype;

        $group = $_SESSION['authProvider'] ?? "";
        $success = (int)($outcome !== false);
        $this->recordLogItem($success, $event, $user, $group, $comments, $pid, $category);
    }

    /**
     * Parse SET clause used in both UPDATE and INSERT ... SET statements
     *
     * @param string $setClause The SET portion of the query
     * @return array Key-value pairs of column names and their values
     */
    private function parseInsertSetClause($setClause)
    {
        $values = [];
        $setParts = explode(',', $setClause);

        foreach ($setParts as $setPart) {
            if (preg_match('/`?(\w+)`?\s*=\s*(.+)/i', trim($setPart), $matches)) {
                $column = trim($matches[1], '`\'" ');
                $value = trim($matches[2]);

                // Handle NULL values
                if (strtoupper($value) === 'NULL') {
                    $values[$column] = null;
                    continue;
                }

                // Remove surrounding quotes if present
                $values[$column] = trim($value, "'\"");
            }
        }

        return $values;
    }

    /**
     * May-29-2014: Ensoftek: For Auditable events and tamper-resistance (MU2)
     * Insert Audit Logging Status into the LOG table.
     *
     * @param $enable
     */
    public function auditSQLAuditTamper($setting, $enable)
    {
        $user =  $_SESSION['authUser'] ?? "";
        $group = $_SESSION['authProvider'] ?? "";
        $pid = 0;
        $success = 1;
        $event = "security-administration" . "-" . "insert";

        if ($setting == 'enable_auditlog') {
            $comments = "Audit Logging";
        } elseif ($setting == 'gbl_force_log_breakglass') {
            $comments = "Force Breakglass Logging";
        } else {
            $comments = $setting;
        }

        if ($enable == "1") {
            $comments .= " Enabled.";
        } else {
            $comments .= " Disabled.";
        }

        $this->recordLogItem($success, $event, $user, $group, $comments, $pid);
    }

    /**
     * Record the patient disclosures.
     *
     * @param $dates    - The date when the disclosures are sent to the thrid party.
     * @param $event    - The type of the disclosure.
     * @param $pid      - The id of the patient for whom the disclosures are recorded.
     * @param $comment  - The recipient name and description of the disclosure.
     * @uname - The username who is recording the disclosure.
     */
    public function recordDisclosure($dates, $event, $pid, $recipient, $description, $user)
    {
        $adodb = $GLOBALS['adodb']['db'];
        $sql = "insert into extended_log ( date, event, user, recipient, patient_id, description) " .
            "values (" . $adodb->qstr($dates) . "," . $adodb->qstr($event) . "," . $adodb->qstr($user) .
            "," . $adodb->qstr($recipient) . "," .
            $adodb->qstr($pid) . "," .
            $adodb->qstr($description) . ")";
        $ret = sqlInsertClean_audit($sql);
    }

    /**
     * Edit the disclosures that is recorded.
     *
     * @param $dates  - The date when the disclosures are sent to the thrid party.
     * @param $event  - The type of the disclosure.
     * param $comment - The recipient and the description of the disclosure are appended.
     * $logeventid    - The id of the record which is to be edited.
     */
    public function updateRecordedDisclosure($dates, $event, $recipient, $description, $disclosure_id)
    {
        $adodb = $GLOBALS['adodb']['db'];
        $sql = "update extended_log set
                event=" . $adodb->qstr($event) . ",
                date=" .  $adodb->qstr($dates) . ",
                recipient=" . $adodb->qstr($recipient) . ",
                description=" . $adodb->qstr($description) . "
                where id=" . $adodb->qstr($disclosure_id) . "";
        $ret = sqlInsertClean_audit($sql);
    }

    /**
     * Delete the disclosures that is recorded.
     *
     * @param $deletelid - The id of the record which is to be deleted.
     */
    public function deleteDisclosure($deletelid)
    {
        $sql = "delete from extended_log where id='" . add_escape_custom($deletelid) . "'";
        $ret = sqlInsertClean_audit($sql);
    }

    public function recordLogItem($success, $event, $user, $group, $comments, $patientId = null, $category = null, $logFrom = 'open-emr', $menuItemId = null, $ccdaDocId = null, $user_notes = '', $api = null)
    {
        if ($patientId == "NULL") {
            $patientId = null;
        }

        // Encrypt if applicable
        if (!isset($this->cryptoGen)) {
            $this->cryptoGen = new CryptoGen();
        }
        $version = '4'; // default to old version

        if (is_array($comments) || is_object($comments)) {
            // Already structured data, convert to JSON
            $comments = json_encode($comments);
            if ($comments == false) {
                $comments = 'Error: JSON encoding failed';
            }
            $version = '5';
        }
        $encrypt = 'No';
        if (!empty($GLOBALS["enable_auditlog_encryption"])) {
            // encrypt the comments field
            $comments =  $this->cryptoGen->encryptStandard($comments);
            if (!empty($api)) {
                // api log
                $api['request_url'] = (!empty($api['request_url'])) ? $this->cryptoGen->encryptStandard($api['request_url']) : '';
                $api['request_body'] = (!empty($api['request_body'])) ? $this->cryptoGen->encryptStandard($api['request_body']) : '';
                $api['response'] =  (!empty($api['response'])) ? $this->cryptoGen->encryptStandard($api['response']) : '';
            }
            $encrypt = 'Yes';
        } else {
            // Since storing binary elements (uuid), need to base64 to not jarble them and to ensure the auditing hashing works
            $comments = base64_encode($comments);
        }

        // Collect timestamp and if pertinent, collect client cert name
        $current_datetime = date("Y-m-d H:i:s");
        $SSL_CLIENT_S_DN_CN = $_SERVER['SSL_CLIENT_S_DN_CN'] ?? '';

        // Note that no longer using checksum field in log table in OpenEMR 6.0 and onward since using the checksum in log_comment_encrypt table.
        //  Need to keep to maintain backward compatibility since the checksum is used when calculating checksum stored in log_comment_encrypt table
        //   in pre 6.0.
        // Note also need to use lower case for 'insert into' to prevent a endless loop
        // Steps:
        //  1. insert entry into log table
        //  2. insert associated entry into log_comment_encrypt
        //  3. if api log entry, then insert insert associated entry into api_log
        //  4. if atna server is on, then send entry to atna server
        //
        // 1. insert entry into log table
        $logEntry = [
            $current_datetime,
            $event,
            $category,
            $user,
            $group,
            $comments,
            $user_notes,
            $patientId,
            $success,
            $SSL_CLIENT_S_DN_CN,
            $logFrom,
            $menuItemId,
            $ccdaDocId
        ];
        sqlInsertClean_audit("insert into `log` (`date`, `event`, `category`, `user`, `groupname`, `comments`, `user_notes`, `patient_id`, `success`, `crt_user`, `log_from`, `menu_item_id`, `ccda_doc_id`) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", $logEntry);
        // 2. insert associated entry (in addition to calculating and storing applicable checksums) into log_comment_encrypt
        $last_log_id = $GLOBALS['adodb']['db']->Insert_ID();
        $checksumGenerate = hash('sha3-512', implode($logEntry));
        if (!empty($api)) {
            // api log
            $ipAddress = collectIpAddresses()['ip_string'];
            $apiLogEntry = [
                $last_log_id,
                $api['user_id'],
                $api['patient_id'],
                $ipAddress,
                $api['method'],
                $api['request'],
                $api['request_url'],
                $api['request_body'],
                $api['response'],
                $current_datetime
            ];
            $checksumGenerateApi = hash('sha3-512', implode($apiLogEntry));
        } else {
            $checksumGenerateApi = '';
        }
        sqlInsertClean_audit(
            "INSERT INTO `log_comment_encrypt` (`log_id`, `encrypt`, `checksum`, `checksum_api`, `version`) VALUES (?, ?, ?, ?, ?)",
            [
                $last_log_id,
                $encrypt,
                $checksumGenerate,
                $checksumGenerateApi,
                $version
            ]
        );
        // 3. if api log entry, then insert insert associated entry into api_log
        if (!empty($api)) {
            // api log
            sqlInsertClean_audit("INSERT INTO `api_log` (`log_id`, `user_id`, `patient_id`, `ip_address`, `method`, `request`, `request_url`, `request_body`, `response`, `created_time`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", $apiLogEntry);
        }
        // 4. if atna server is on, then send entry to atna server
        if ($patientId == null) {
            $patientId = 0;
        }
        $this->sendAtnaAuditMsg($user, $group, $event, $patientId, $success, $comments);
    }

    /**
     * Log HTTP request details
     */
    public function logHttpRequest()
    {
        // Skip if audit logging or http request logging is disabled
        if (empty($GLOBALS['enable_auditlog']) || empty($GLOBALS['audit_events_http-request'])) {
            return;
        }

        // Rest of the existing method code remains the same...
        // Skip certain paths we don't want to log
        // TODO: @adunsulag do we want to skip the log page? I think that's useful auditing information
//        if (strpos($_SERVER['SCRIPT_NAME'], 'logview.php') !== false) {
//            return; // Don't log requests to logging pages
//        }

        // Map HTTP methods to event action types
        $methodMap = [
            'GET' => 'select',
            'POST' => 'update',
            'PUT' => 'update',
            'DELETE' => 'delete',
            'PATCH' => 'update'
        ];

        $method = $_SERVER['REQUEST_METHOD'];
        $event = $methodMap[$method] ?? 'select';

        // Build the comment with path and query params
        $comment = $_SERVER['SCRIPT_NAME'];
        if (!empty($_SERVER['QUERY_STRING'])) {
            $comment .= '?' . $_SERVER['QUERY_STRING'];
        }

        // Record the log entry
        $this->newEvent(
            "http-request-$event",  // event
            $_SESSION['authUser'] ?? null, // user
            $_SESSION['authProvider'] ?? null, // groupname
            1, // success
            $comment, // comments
            $_SESSION['pid'] ?? null, // patient_id
            'http-request', // log_from
            null, // menu_item
            0 // ccda_doc_id
        );
    }

    /**
     * Function used to determine category of the event
     *
     * @param  $sql
     * @param  $event
     * @param  $table
     * @return string
     */
    private function eventCategoryFinder($sql, $event, $table)
    {
        if ($event == 'delete') {
            if (strpos($sql, "lists:") === 0) {
                $fieldValues    = explode("'", $sql);
                if (in_array('medical_problem', $fieldValues) === true) {
                    return 'Problem List';
                } elseif (in_array('medication', $fieldValues) === true) {
                    return 'Medication';
                } elseif (in_array('allergy', $fieldValues) === true) {
                    return 'Allergy';
                }
            }
        }

        if ($table == 'lists' || $table == 'lists_touch') {
            $trimSQL        = stristr($sql, $table);
            $fieldValues    = explode("'", $trimSQL);
            if (in_array('medical_problem', $fieldValues) === true) {
                return 'Problem List';
            } elseif (in_array('medication', $fieldValues) === true) {
                return 'Medication';
            } elseif (in_array('allergy', $fieldValues) === true) {
                return 'Allergy';
            }
        } elseif ($table == 'immunizations') {
            return "Immunization";
        } elseif ($table == 'form_vitals') {
            return "Vitals";
        } elseif ($table == 'history_data') {
            return "Social and Family History";
        } elseif ($table == 'forms' || $table == 'form_encounter' || strpos($table, 'form_') === 0) {
            return "Encounter Form";
        } elseif ($table == 'insurance_data') {
            return "Patient Insurance";
        } elseif ($table == 'patient_data' || $table == 'employer_data') {
            return "Patient Demographics";
        } elseif ($table == 'payments' || $table == "billing" || $table == "claims") {
            return "Billing";
        } elseif ($table == 'pnotes') {
            return "Clinical Mail";
        } elseif ($table == 'prescriptions') {
            return "Medication";
        } elseif ($table == 'transactions') {
            $trimSQL        = stristr($sql, "transactions");
            $fieldValues    = explode("'", $trimSQL);
            if (in_array("LBTref", $fieldValues)) {
                return "Referral";
            } else {
                return $event;
            }
        } elseif ($table == 'amendments' || $table == 'amendments_history') {
            return "Amendments";
        } elseif ($table == 'openemr_postcalendar_events') {
            return "Scheduling";
        } elseif ($table == 'procedure_order' || $table == 'procedure_order_code') {
            return "Lab Order";
        } elseif ($table == 'procedure_report' || $table == 'procedure_result') {
            return "Lab Result";
        } elseif ($event == 'security-administration') {
            return "Security";
        }

        return $event;
    }

    // Goal of this function is to increase performance in logging engine to check
    //  if a user is a breakglass user (in this case, will log all activities if the
    //  setting is turned on in Administration->Logging->'Audit all Emergency User Queries').
    private function isBreakglassUser($user)
    {
        // return false if $user is empty
        if (empty($user)) {
            return false;
        }

        // Return the breakglass user flag if it exists already (it is cached by this singleton class to speed the logging engine up)
        if (isset($this->breakglassUser)) {
            return $this->breakglassUser;
        }

        // see if current user is in the breakglass group
        //  note we are bypassing gacl standard api to improve performance
        $queryUser = sqlQueryNoLog(
            "SELECT `gacl_aro`.`value`
            FROM `gacl_aro`, `gacl_groups_aro_map`, `gacl_aro_groups`
            WHERE `gacl_aro`.`id` = `gacl_groups_aro_map`.`aro_id`
            AND `gacl_groups_aro_map`.`group_id` = `gacl_aro_groups`.`id`
            AND `gacl_aro_groups`.`value` = 'breakglass'
            AND BINARY `gacl_aro`.`value` = ?",
            [$user]
        );
        if (empty($queryUser)) {
            // user is not in breakglass group
            $this->breakglassUser = false;
        } else {
            // user is in breakglass group
            $this->breakglassUser = true;
        }
        return $this->breakglassUser;
    }

    public function isSqlUpdateStatement(string $sql)
    {
        return stripos(trim($sql), 'UPDATE') === 0;
    }

    public function getPreviousValues(string $sql, bool|array $inputarr)
    {
        $sql = $this->applyBinds($sql, $inputarr);
        // Parse the UPDATE statement
        if (preg_match('/UPDATE\s+(`?)(\w+)\1\s+SET\s+(.+?)\s+WHERE\s+(.+)/i', $sql, $matches)) {
            $table = $matches[2];
            $whereClause = $matches[4];
            $setClause = $matches[3];

            // Extract fields being updated
            $changedFields = [];
            $setParts = explode(',', $setClause);
            foreach ($setParts as $setPart) {
                if (preg_match('/`?(\w+)`?\s*=\s*(.+)/', trim($setPart), $fieldMatch)) {
                    $changedFields[] = $fieldMatch[1];
                }
            }

            // Count the number of bound parameters (?) in WHERE clause
            $whereParamCount = substr_count($whereClause, '?');

            // If we have bound parameters, get them from the end of inputarr
            $whereParams = [];
            if ($whereParamCount > 0 && is_array($inputarr)) {
                $whereParams = array_slice($inputarr, -$whereParamCount);
            }

            // Build and execute SELECT for previous values
            if (!empty($changedFields)) {
                $selectFields = implode('`, `', $changedFields);
                $selectSQL = "SELECT `$selectFields` FROM `$table` WHERE $whereClause";

                // Execute without prepared statement
                $selectSQL = $this->applyBinds($selectSQL, $whereParams);
                $result = sqlQueryNoLog($selectSQL);
                if ($result !== false) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * Check if this SQL statement should be logged
     * @param string $statement
     * @return bool
     */
    public function shouldLogSql($statement)
    {
        $statement = trim($statement);

        // Skip if audit logging is disabled (unless break glass user)
        if (empty($GLOBALS['enable_auditlog'])) {
            if (empty($GLOBALS['gbl_force_log_breakglass']) || !$this->isBreakglassUser($user)) {
                return false;
            }
        }

        // Skip logging of log table activity to avoid recursion
        if (stripos($statement, "insert into log") !== false ||
            stripos($statement, "insert into `log`") !== false ||
            stripos($statement, "FROM log ") !== false ||
            stripos($statement, "FROM `log` ") !== false ||
            strpos($statement, "sequences") !== false) {
            return false;
        }

        return true;
    }

    /**
     * @return void
     */
    private function applyBinds($sql, $binds): string
    {
        if (empty($binds)) {
            return $sql;
        }

        $bindIndex = 0;
        return preg_replace_callback('/\?/', function ($matches) use ($binds, &$bindIndex) {
            $value = $binds[$bindIndex++];
            if ($value === '') {
                return "''";
            } else if (is_null($value)) {
                return 'NULL';
            } elseif (is_numeric($value)) {
                return $value;
            } else {
                return "'" . add_escape_custom($value) . "'";
            }
        }, $sql);
    }

    /**
     * Process a record array to handle binary data
     *
     * @param array $record The record data to process
     * @return array The processed record with binary data encoded
     */
    private function processBinaryData($record)
    {
        if (empty($record) || !is_array($record)) {
            return $record;
        }

        // List of known binary columns
        $binaryColumns = ['uuid', 'drive_uuid'];

        foreach ($record as $key => $value) {
            // Skip null values
            if (is_null($value)) {
                continue;
            }

            // Check if this is a known binary column or appears to contain binary data
            if (in_array($key, $binaryColumns) || $this->isBinary($value)) {
                $record[$key] = [
                    'type' => 'binary',
                    'value' => base64_encode($value)
                ];
            }
        }

        return $record;
    }

    /**
     * Check if a string contains binary data
     *
     * @param string $str The string to check
     * @return bool True if the string contains binary data
     */
    private function isBinary($str)
    {
        if (!is_string($str)) {
            return false;
        }
        // Check if the string contains any non-printable characters except for whitespace
        return preg_match('/[^\PC\s]/u', $str) !== 0;
    }

    /**
     * Parse REPLACE/INSERT queries to extract table, columns, and values for audit logging.
     * Handles SET syntax and VALUES syntax for both REPLACE INTO and INSERT INTO.
     *
     * @param string $rawQuery The complete SQL query with binds applied
     * @return array|null Returns array with table, before and after states, or null if parsing fails
     */
    private function parseInsertQuery($rawQuery)
    {
        $result = [
            'table' => '',
            'before' => [],
            'after' => [],
            'isReplace' => false
        ];

        // Determine if this is a REPLACE query
        $isReplace = stripos(trim($rawQuery), 'REPLACE INTO') === 0;
        $result['isReplace'] = $isReplace;

        // First try to match SET syntax
        $pattern = '/(?:INSERT|REPLACE)\s+INTO\s+(`?)(\w+)\1\s+SET\s+(.*?)(?:;|\s*$)/is';
        if (preg_match($pattern, $rawQuery, $matches)) {
            $result['table'] = $matches[2];
            $setClause = $matches[3];

            // Parse the SET clause
            $afterValues = $this->parseSetClause($setClause);
            if (!empty($afterValues)) {
                $result['after'] = $this->processBinaryData($afterValues);

                // If this is a REPLACE query and we have a primary key, try to get the existing record
                if ($isReplace && isset($afterValues['id'])) {
                    $beforeValues = $this->getPreviousRecord($result['table'], $afterValues['id']);
                    $result['before'] = $this->processBinaryData($beforeValues ?: array_fill_keys(array_keys($afterValues), ''));
                } else {
                    $result['before'] = array_fill_keys(array_keys($afterValues), '');
                }
                return $result;
            }
        }

        // If SET syntax didn't match, try VALUES syntax
        $pattern = '/(?:INSERT|REPLACE)\s+INTO\s+(`?)(\w+)\1\s*\((.*?)\)\s*VALUES\s*\((.*)\)/is';
        if (preg_match($pattern, $rawQuery, $matches)) {
            $result['table'] = $matches[2];

            // Parse columns
            $columns = array_map(function($col) {
                return trim($col, '`\'" ');
            }, str_getcsv($matches[3]));

            // Extract values while respecting nested structures
            $valuesList = $this->parseValuesList($matches[4]);

            if (count($columns) === count($valuesList)) {
                $afterValues = array_combine($columns, $valuesList);
                $result['after'] = $this->processBinaryData($afterValues);

                // If this is a REPLACE query and we have a primary key column, try to get the existing record
                if ($isReplace) {
                    $idKey = array_search('id', $columns);
                    if ($idKey !== false && isset($valuesList[$idKey])) {
                        $beforeValues = $this->getPreviousRecord($result['table'], $valuesList[$idKey]);
                        $result['before'] = $this->processBinaryData($beforeValues ?: array_fill_keys($columns, ''));
                    } else {
                        $result['before'] = array_fill_keys($columns, '');
                    }
                } else {
                    $result['before'] = array_fill_keys($columns, '');
                }
                return $result;
            }
        }

        // Return default if parsing failed
        return $result;
    }

    /**
     * Parse SET clause used in both UPDATE and INSERT ... SET statements
     *
     * @param string $setClause The SET portion of the query
     * @return array Key-value pairs of column names and their values
     */
    private function parseSetClause($setClause)
    {
        $values = [];

        // Split on commas, but keep track of quotes to avoid splitting within quoted strings
        $parts = [];
        $currentPart = '';
        $inQuote = false;
        $quoteChar = '';

        // Normalize line endings and remove extra whitespace
        $setClause = str_replace(["\r\n", "\r"], "\n", $setClause);

        for ($i = 0; $i < strlen($setClause); $i++) {
            $char = $setClause[$i];

            // Handle quotes
            if (($char === "'" || $char === '"') && ($i === 0 || $setClause[$i - 1] !== '\\')) {
                if (!$inQuote) {
                    $inQuote = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuote = false;
                }
            }

            // Split on commas only when not in quotes
            if ($char === ',' && !$inQuote) {
                $parts[] = trim($currentPart);
                $currentPart = '';
                continue;
            }

            $currentPart .= $char;
        }

        // Add the last part
        if ($currentPart !== '') {
            $parts[] = trim($currentPart);
        }

        // Process each part
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^`?(\w+)`?\s*=\s*(.+)$/s', $part, $matches)) {
                $column = trim($matches[1], '`\'" ');
                $value = trim($matches[2]);

                // Process the value
                if (strtoupper($value) === 'NULL') {
                    $values[$column] = null;
                }
                // Handle function calls like NOW()
                elseif (preg_match('/^(\w+)\(\)$/i', $value)) {
                    $values[$column] = $value;
                }
                // Handle quoted strings
                elseif (($value[0] === "'" && substr($value, -1) === "'") ||
                    ($value[0] === '"' && substr($value, -1) === '"')) {
                    $values[$column] = substr($value, 1, -1);
                }
                // Handle other values
                else {
                    $values[$column] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Parse a VALUES list while respecting nested structures and functions
     *
     * @param string $valueString The string containing the values
     * @return array The parsed values
     */
    private function parseValuesList($valueString)
    {
        $values = [];
        $currentValue = '';
        $inQuote = false;
        $quoteChar = '';
        $parenthesesCount = 0;
        $len = strlen($valueString);

        for ($i = 0; $i < $len; $i++) {
            $char = $valueString[$i];

            // Handle quotes
            if (($char === "'" || $char === '"') && $valueString[$i - 1] !== '\\') {
                if (!$inQuote) {
                    $inQuote = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuote = false;
                }
            }

            // Handle parentheses
            if (!$inQuote) {
                if ($char === '(') {
                    $parenthesesCount++;
                } elseif ($char === ')') {
                    $parenthesesCount--;
                }
            }

            // Handle value separation
            if ($char === ',' && !$inQuote && $parenthesesCount === 0) {
                $values[] = $this->processValue(trim($currentValue));
                $currentValue = '';
                continue;
            }

            $currentValue .= $char;
        }

        // Add the last value
        if ($currentValue !== '') {
            $values[] = $this->processValue(trim($currentValue));
        }

        return $values;
    }

    /**
     * Process a single value from the VALUES clause
     *
     * @param string $value The value to process
     * @return mixed The processed value
     */
    private function processValue($value)
    {
        // Handle NULL
        if (strtoupper($value) === 'NULL') {
            return null;
        }

        // Handle functions like NOW()
        if (preg_match('/^(\w+)\(\)$/i', $value)) {
            return $value;
        }

        // Remove surrounding quotes if present
        if (($value[0] === "'" && substr($value, -1) === "'") ||
            ($value[0] === '"' && substr($value, -1) === '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
