#!/usr/bin/php
<?php
// script to run as process in background
// polling and processing new messages/requests

require_once('config.php');
require_once('dcc_lib.php');

while (1) {
    manage_files();
    check_certificate_validation_requests($DCC_CFG->connectorid, $DCC_CFG->xapikey, $DCC_CFG->host, true, false);
    check_certificate_storage_requests($DCC_CFG->connectorid, $DCC_CFG->xapikey, $DCC_CFG->host, true, false);
    check_certificate_revocation_requests($DCC_CFG->connectorid, $DCC_CFG->xapikey, $DCC_CFG->host, true, false);
    sleep(5);
}