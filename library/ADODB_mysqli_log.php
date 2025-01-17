<?php

/**
 * ADODB custom wrapper class to support auditing engine in main.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Common\Logging\EventAuditLogger;

class ADODB_mysqli_log extends ADODB_mysqli
{
    /**
     * ADODB Execute function wrapper to ensure proper auditing in OpenEMR.
     *
     * @param  string  $sql         query
     * @param  array   $inputarr    binded variables array (optional)
     * @return boolean              returns false if error
     */
    function Execute($sql, $inputarr = false, $insertNeedReturn = false)
    {
        $logger = EventAuditLogger::instance();

        // Check if we should log this SQL
        $shouldLog = $logger->shouldLogSql($sql);
        $previousValues = null;

        // If logging and it's an UPDATE, capture previous values
        if ($shouldLog && $logger->isSqlUpdateStatement($sql)) {
            $previousValues = $logger->getPreviousValues($sql, $inputarr);
        }

        $retval = parent::Execute($sql, $inputarr);
        if ($retval === false) {
            $outcome = false;
            // Stash the error into last_mysql_error so it doesn't get clobbered when
            // we insert into the audit log.
            $GLOBALS['last_mysql_error'] = $this->ErrorMsg();

            // Last error no
            $GLOBALS['last_mysql_error_no'] = $this->ErrorNo();
        } else {
            $outcome = true;
        }

        // Stash the insert ID into lastidado so it doesn't get clobbered when
        // we insert into the audit log.
        if ($insertNeedReturn) {
            $GLOBALS['lastidado'] = $this->Insert_ID();
        }
        // Log the event if needed
        if ($shouldLog) {
            $logger->auditSQLEvent($sql, $outcome, $inputarr, $previousValues);
        }
        return $retval;
    }

    /**
     * ADODB Execute function wrapper to skip auditing in OpenEMR.
     *
     * Bypasses the OpenEMR auditing engine.
     *
     * @param  string  $sql         query
     * @param  array   $inputarr    binded variables array (optional)
     * @return boolean              returns false if error
     */
    function ExecuteNoLog($sql, $inputarr = false)
    {
        return parent::Execute($sql, $inputarr);
    }
}
