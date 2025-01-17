<?php

namespace OpenEMR\Controllers\Logging;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Core\Header;

class LogViewController
{
    private $twig;
    private $cryptoGen;
    private $eventLogger;

    public function __construct($twig)
    {
        $this->twig = $twig;
        $this->cryptoGen = new CryptoGen();
        $this->eventLogger = EventAuditLogger::instance();
    }

    public function index()
    {
        if (!empty($_GET) && !CsrfUtils::verifyCsrfToken($_GET["csrf_token_form"])) {
            CsrfUtils::csrfNotVerified();
        }

        $data = $this->getViewData();

        return $this->twig->render('logging/logview.html.twig', $data);
    }

    private function getViewData()
    {
        $start_date = (!empty($_GET["start_date"])) ? $_GET["start_date"] : date("Y-m-d") . " 00:00";
        $end_date = (!empty($_GET["end_date"])) ? $_GET["end_date"] : date("Y-m-d") . " 23:59";

        // Get the rest of the form parameters
        $form_user = $_REQUEST['form_user'] ?? '';
        $form_patient = $_GET["form_patient"] ?? '';
        $form_pid = $_REQUEST['form_pid'] ?? '';
        if (empty($form_patient)) {
            $form_pid = '';
        }

        $eventname = $_GET['eventname'] ?? '';
        $type_event = $_GET['type_event'] ?? '';
        $sortby = $_GET['sortby'] ?? '';
        $direction = $_GET['direction'] ?? '';

        $logs = $this->getLogEntries($start_date, $end_date, $form_user, $form_pid, $eventname, $type_event, $sortby, $direction);

        return [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'form_user' => $form_user,
            'form_patient' => $form_patient,
            'form_pid' => $form_pid,
            'eventname' => $eventname,
            'type_event' => $type_event,
            'sortby' => $sortby,
            'direction' => $direction,
            'logs' => $logs,
            'users' => $this->getUsers(),
            'events' => $this->getEventsList(),
            'csrfToken' => CsrfUtils::collectCsrfToken(),
            'show' => true
        ];
    }

    public function getLogSummary($table, $logData)
    {
        $summaryText = '';
        $actionIcon = '';
        $iconClass = '';

        switch($logData['type']) {
            case 'update':
                $iconClass = 'text-warning';
                $actionIcon = 'fa-edit';
//                $actionIcon = '<i class="fas fa-edit text-warning mr-1"></i>';
                $summaryText = "Updated " . $this->getHumanReadableTableName($table, $logData);
                if (!empty($logData['where'])) {
                    if (preg_match('/(\w+)\s*=\s*[\'"]?(\d+)[\'"]?/', $logData['where'], $matches)) {
                        $summaryText .= " #" . $matches[2];
                    }
                }
                break;

            case 'insert':
                $iconClass = 'text-success';
                $actionIcon = 'fa-plus';
//                $actionIcon = '<i class="fas fa-plus text-success mr-1"></i>';
                $summaryText = "Created new " . $this->getHumanReadableTableName($table, $logData);
                break;

            case 'delete':
                $iconClass = 'text-danger';
                $actionIcon = 'fa-trash';
//                $actionIcon = '<i class="fas fa-trash text-danger mr-1"></i>';
                $summaryText = "Deleted " . $this->getHumanReadableTableName($table, $logData);
                break;

            default:
                $iconClass = 'text-info';
                $actionIcon = 'fa-search';
//                $actionIcon = '<i class="fas fa-search text-info mr-1"></i>';
                $summaryText = "Query " . $this->getHumanReadableTableName($table, $logData);
        }

        return [
            'icon' => $actionIcon,
            'icon_class' => $iconClass,
            'text' => $summaryText
        ];
    }

    private function getUsers()
    {
        // Get the users list who are active and not marked as inactive in their info field
        $sqlQuery = "SELECT username, fname, lname FROM users " .
            "WHERE active = 1 AND ( info IS NULL OR info NOT LIKE '%Inactive%' ) " .
            "ORDER BY lname, fname";

        $users = [];
        $ures = sqlStatement($sqlQuery);
        while ($urow = sqlFetchArray($ures)) {
            if (empty(trim($urow['username'] ?? ''))) {
                continue;
            }
            $users[] = [
                'username' => $urow['username'],
                'name' => $urow['lname'] . ($urow['fname'] ? ", " . $urow['fname'] : "")
            ];
        }
        return $users;
    }

    private function getEventsList()
    {
        // Get distinct events from log table
        $res = sqlStatement("select distinct event from log order by event ASC");
        $ename_list = [];
        $j = 0;
        while ($erow = sqlFetchArray($res)) {
            if (!trim($erow['event'])) {
                continue;
            }
            $data = explode('-', $erow['event']);
            $data_c = count($data);
            $ename = $data[0];
            for ($i = 1; $i < ($data_c - 1); $i++) {
                $ename .= "-" . $data[$i];
            }
            $ename_list[$j] = $ename;
            $j++;
        }

        // Get events from extended_log table
        $res1 = sqlStatement("select distinct event from extended_log order by event ASC");
        while ($row = sqlFetchArray($res1)) {
            if (!trim($row['event'])) {
                continue;
            }
            $new_event = explode('-', $row['event']);
            $no = count($new_event);
            $events = $new_event[0];
            for ($i = 1; $i < ($no - 1); $i++) {
                $events .= "-" . $new_event[$i];
            }
            if ($events == "disclosure") {
                $ename_list[$j] = $events;
            }
            $j++;
        }

        // Remove duplicates and reindex array
        $ename_list = array_unique($ename_list);
        $ename_list = array_values($ename_list);

        return $ename_list;
    }

    private function getHumanReadableTableName($table, $logData)
    {
        if ($table == 'lists' || $table == 'lists_touch') {
            if (isset($logData['after']['type'])) {
                switch ($logData['after']['type']) {
//                    case 'medical_problem':
//                        return xl('Problem');
//                    break;
//                    case 'allergy':
//                        return xl('Allergy');
//                    break;
//                    case 'medication':
//                        return xl('Medication');
//                    break;
//                    case 'immunization':
//                        return xl('Immunization');
//                    break;
//                    case 'surgery':
//                        return xl('Surgery');
//                    break;
                    default:
                        return implode(" ", array_map('ucfirst', explode('_', $logData['after']['type'])));
                }
            }
        }
        // Use the logic from eventCategoryFinder
        $categories = [
            'prescriptions' => 'Medications',
            'form_vitals' => 'Vital Signs',
            'immunizations' => 'Immunizations',
            'history_data' => 'History',
            'insurance_data' => 'Insurance',
            'patient_data' => 'Patient Demographics',
            // Add more mappings as needed
        ];

        return $categories[$table] ?? ucfirst($table);
    }

    // Add other helper methods for users list, events list, etc.
    private function getLogEntries($start_date, $end_date, $form_user, $form_pid, $eventname, $type_event, $sortby, $direction)
    {
        $tevent = "";
        $gev = "";
        if ($eventname != "" && $type_event != "") {
            $getevent = $eventname . "-" . $type_event;
        }

        if (($eventname == "") && ($type_event != "")) {
            $tevent = $type_event;
        } elseif ($type_event == "" && $eventname != "") {
            $gev = $eventname;
        } elseif ($eventname == "") {
            $gev = "";
        } else {
            $gev = $getevent;
        }

        $logEntries = [];

        // Get standard log entries
        $ret = $this->eventLogger->getEvents([
            'sdate' => $start_date,
            'edate' => $end_date,
            'user' => $form_user,
            'patient' => $form_pid,
            'sortby' => $sortby,
            'levent' => $gev,
            'tevent' => $tevent,
            'direction' => $direction
        ]);

        if ($ret) {
            while ($iter = sqlFetchArray($ret)) {
                if (empty($iter['id'])) {
                    //skip empty log items (this means they were deleted and will show up as deleted in the audit log tamper script)
                    continue;
                }

                $logEntry = $this->processLogEntry($iter);
                if ($logEntry) {
                    $logEntries[] = $logEntry;
                }
            }
        }

        // Handle disclosure events separately if needed
        if (($eventname == "disclosure") || ($gev == "")) {
            $eventname = "disclosure";
            $ret = $this->eventLogger->getEvents([
                'sdate' => $start_date,
                'edate' => $end_date,
                'user' => $form_user,
                'patient' => $form_pid,
                'sortby' => $sortby,
                'event' => $eventname
            ]);

            if ($ret) {
                while ($iter = sqlFetchArray($ret)) {
                    $disclosureEntry = $this->processDisclosureEntry($iter);
                    if ($disclosureEntry) {
                        $logEntries[] = $disclosureEntry;
                    }
                }
            }
        }

        return $logEntries;
    }

    private function processLogEntry($iter)
    {
        // Get encryption status
        $commentEncrStatus = $iter['encrypt'] ?? "No";
        $encryptVersion = $iter['version'] ?? 0;

        $comments = $iter["comments"];
        if ($commentEncrStatus == "Yes") {
            $comments = $this->decryptComments($comments, $encryptVersion);
        } elseif ($encryptVersion >= 4) {
            // Handle base64 encoding for non-encrypted comments in OpenEMR 6.0.0+
            $comments = base64_decode($comments);
        }
        $processedComments = $comments;
        $summary = null;

        // For version 5+ logs, handle structured data
        if ($encryptVersion >= 5) {
            $logData = json_decode($comments, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Store the original JSON data
                $processedComments = $logData;
                // Generate the summary
                $summary = $this->getLogSummary($logData['table'] ?? '', $logData);
            }
        }

        return [
            'date' => $iter['date'],
            'event' => preg_replace('/select$/', 'Query', $iter['event']), //Convert select term to Query for MU2 requirements
            'category' => $iter['category'],
            'user' => $iter['user'],
            'crt_user' => $iter['crt_user'],
            'groupname' => $iter['groupname'],
            'patient_id' => $iter['patient_id'],
            'success' => $iter['success'],
            'comments' => $processedComments,
            'log_from' => $iter['log_from'],
            'menu_item_id' => $iter['menu_item_id'],
            'ccda_doc_id' => $iter['ccda_doc_id'],
            'summary' => $summary,
            'encryption' => [
                'status' => $commentEncrStatus,
                'version' => $encryptVersion
            ],
            'ip_address' => $iter['ip_address'] ?? null,
            'method' => $iter['method'] ?? null,
            'request' => $iter['request'] ?? null
        ];
    }

    private function processDisclosureEntry($iter)
    {
        $comments = xl('Recipient Name') . ":" . $iter["recipient"] . ";" . xl('Disclosure Info') . ":" . $iter["description"];

        return [
            'date' => $iter['date'],
            'event' => $iter['event'],
            'category' => $iter['category'] ?? '',
            'user' => $iter['user'],
            'crt_user' => $iter['crt_user'] ?? '',
            'groupname' => $iter['groupname'] ?? '',
            'patient_id' => $iter['patient_id'],
            'success' => $iter['success'] ?? '',
            'comments' => $comments,
            'summary' => null,
//            'summary' => [
//                'icon' => 'fa-user-shield',
//                'icon_class' => 'text-danger',
//                'text' => $comments
//            ],
            'is_disclosure' => true
        ];
    }

    private function decryptComments($comments, $encryptVersion)
    {
        $patterns = array('/^success/', '/^failure/', '/ encounter/');
        $replace = array(xl('success'), xl('failure'), xl('encounter', '', ' '));

        if ($encryptVersion >= 3) {
            // Use new openssl method
            if (extension_loaded('openssl')) {
                $trans_comments = $this->cryptoGen->decryptStandard($comments);
                if ($trans_comments !== false) {
                    return preg_replace($patterns, $replace, $trans_comments);
                } else {
                    return xl("Unable to decrypt these comments since decryption failed.");
                }
            } else {
                return xl("Unable to decrypt these comments since the PHP openssl module is not installed.");
            }
        } elseif ($encryptVersion == 2) {
            // Use openssl method
            if (extension_loaded('openssl')) {
                $trans_comments = $this->cryptoGen->aes256DecryptTwo($comments);
                if ($trans_comments !== false) {
                    return preg_replace($patterns, $replace, $trans_comments);
                } else {
                    return xl("Unable to decrypt these comments since decryption failed.");
                }
            } else {
                return xl("Unable to decrypt these comments since the PHP openssl module is not installed.");
            }
        } elseif ($encryptVersion == 1) {
            // Use openssl method
            if (extension_loaded('openssl')) {
                return preg_replace($patterns, $replace, $this->cryptoGen->aes256DecryptOne($comments));
            } else {
                return xl("Unable to decrypt these comments since the PHP openssl module is not installed.");
            }
        } else { //$encryptVersion == 0
            // Use old mcrypt method
            if (extension_loaded('mcrypt')) {
                return preg_replace($patterns, $replace, $this->cryptoGen->aes256Decrypt_mycrypt($comments));
            } else {
                return xl("Unable to decrypt these comments since the PHP mycrypt module is not installed.");
            }
        }
    }
}
