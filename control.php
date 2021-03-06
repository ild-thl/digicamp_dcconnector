<?php
// script to call from javascript

require_once('config.php');
require_once('dcc_lib.php');

$ctl_action = get_post('ctl_action', '');

if ($ctl_action != '') {
    if ($ctl_action == 'check') {
        // Check logs for new reqests and return new requests + results
        $result = new stdClass();
        if (process_running() > 0) {
            $result->process_running = 'true';
            $result->time = time();
            $result->lastlogs = get_lastlogs();
        } else {
            $result->process_running = 'false';
            
        }
        echo json_encode($result);
    }
}