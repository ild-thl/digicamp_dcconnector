<?php
require_once('config.php');
require_once('web3lib.php');

function no_empty_string($value) {
    if ($value === "") {
        return false;
    }
    return true;
}

function process_running() {
    global $DCC_CFG;
    $result = exec('ps aux | grep "php '.$DCC_CFG->script.'" | grep -v grep');
    $pid = 0;
    if ($result != '') {
        $result_array = explode(' ', $result);
        $result_array = array_filter($result_array, "no_empty_string");
        $new_array = array();
        foreach ($result_array as $value) {
            $new_array[] = $value;
        }
        if (count($new_array) > 0 and intval($new_array[1]) > 0) {
            $pid = intval($new_array[1]);
        }
    }
    return $pid;
}

function get_post($key, $default) {
    if (isset($_POST[$key]) and $_POST[$key] != '') {
        return $_POST[$key];
    }
    return $default;
}

function callAPI($method, $url, $data, $xapikey){
    $curl = curl_init();
    switch ($method){
       case "POST":
          curl_setopt($curl, CURLOPT_POST, 1);
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
          break;
       case "PUT":
          curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);			 					
          break;
       default:
          if ($data)
             $url = sprintf("%s?%s", $url, http_build_query($data));
    }
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
       'X-API-KEY: '.$xapikey,
       'Content-Type: application/json',
    ));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    $result = curl_exec($curl);
    if(!$result){die("Connection Failure");}
    curl_close($curl);
    return $result;
}

function log_received_request($messageid, $requesttype, $arrivedInMessages, $valid = 'n.a.') {
    $eol = PHP_EOL;
    $filename = './log/requestlog_'.date("Ymd", time()).'.log';
    if (!file_exists($filename)) {
        $eol = '';
    }
    $logentry = $eol.'[received: '.date("d.m.Y - H:i:s", time()).'][arrived: '.$arrivedInMessages.'][type: '.$requesttype.'][id: '.$messageid.'][valid: '.$valid.']';
    file_put_contents($filename, $logentry, FILE_APPEND);
}

function log_event($eventtype, $eventmessage) {
    $eol = PHP_EOL;
    $filename = './log/eventlog_'.date("Ymd", time()).'.log';
    if (!file_exists($filename)) {
        $eol = '';
    }
    $logentry = $eol.'['.date("d.m.Y - H:i:s", time()).'][type: '.$eventtype.']['.$eventmessage.']';
    file_put_contents($filename, $logentry, FILE_APPEND);
}

function manage_files() {
    create_directories();
    clean_files('log');
    clean_files('temp');
}

function create_directories() {
    if (!is_dir('log')) {
        mkdir('log', 0755);
    }
    if (!is_dir('temp')) {
        mkdir('temp', 0755);
    }
}

function clean_files($dir) {
    global $DCC_CFG;
    if ($dir == 'log' or $dir == 'temp') {
        $dir = './'.$dir.'/';
        $directory = scandir($dir);
        foreach ($directory as $filename) {
            if ($filename != '.' and $filename != '..') {
                $filemtime = filemtime($dir.$filename);
                if (time() - $DCC_CFG->maxfileage > $filemtime) {
                    unlink($dir.$filename);
                }
            }
        }
    }
}

function get_requestlog_element($logentry, $element) {
    $matches = array();
    $text = $logentry;
    preg_match_all("/\[[^\]]*\]/", $text, $matches);
    $logelements = $matches[0];
    $elements = array();
    foreach ($logelements as $logelement) {
        $logelement = str_replace('[', '', $logelement);
        $logelement = str_replace(']', '', $logelement);
        $ex = explode(': ', $logelement);
        $key = $ex[0];
        $value = $ex[1];
        $elements[$key] = $value;
    }
    return $elements[$element];
}

function get_lastlogs() {
    $directory = scandir('./log/', SCANDIR_SORT_DESCENDING);
    if (strpos($directory[0], 'requestlog_') === 0) {
        $filename = $directory[0];
        $lines = file('./log/'.$filename);
        return $lines;
    }
    else {
        $lines = array();
        return $lines;
    }
}

// If no logs are availbale, handle message as unreceived.
// Maximum age will be checked before.
function received($message) {
    // check last available log (requestlog_...) with content
    //echo '<pre>'; var_dump($message); echo '</pre>';
    $lines = get_lastlogs();
    //echo '<pre>'; var_dump($lines); echo '</pre>';
    if (count($lines) > 0) {
        $youngest = get_requestlog_element($lines[0], 'arrived');
        $youngest = strtotime($youngest);
        foreach ($lines as $line) {
            $current = strtotime(get_requestlog_element($line, 'arrived'));
            if ($current > $youngest) {
                $youngest = $current;
            }
        }
        $arrived = strtotime($message->arrivedInMessages);
        //echo '<p>'.$message->id.' $arrived: '.$arrived.' $youngest: '.$youngest.'</p>';
        if ($arrived > $youngest) {
            return false;
        } else {
            return true;
        }
    }
    return false;
}

function get_new_messages($all_messages, $type) {
    global $DCC_CFG;
    $new_messages = array();
    // check config
    if ($DCC_CFG->maxreceivedage > $DCC_CFG->maxfileage) {
        log_event('error', '$DCC_CFG->maxreceivedage must not be higher than $DCC_CFG->maxfileage!');
        return $new_messages;
    }
    foreach($all_messages as $result) {
        if (isset($result->content->type) and  $result->content->type == $type) {
            // Use messages that have not reached maximum age only ($DCC_CFG->maxreceivedage).
            if (strtotime($result->arrivedInMessages) > time() - $DCC_CFG->maxreceivedage) {
                if (!received($result)) {
                    $new_messages[] = $result;
                }
            }
        }
    }
    return $new_messages;
}

/*
{
  "recipients": [
    "MO1CBzRP20QfIcv4lFmw"
  ],
  "content":  {
    "type":"CertificateRevocationRequest",
    "id":"15",
    "hash":"0x4b83fbe94ce2e141623ab64029b80c36a5ad8a2409ea26e8136b7970b6170001",
    "pk":"E31219B838B81B8FB3D84C54BD6E994DC7A08F91D1E343222E2E0051A72D9EEF"
  }
}
*/
function check_certificate_revocation_requests($connectorid, $xapikey, $host, $testmode = false) {
    global $DCC_CFG;
    $get_data = callAPI('GET', $host.'/api/v1/Messages', false, $xapikey);
    $dataobject = json_decode($get_data);
    $resultarray = array();
    $results = get_new_messages($dataobject->result, 'CertificateRevocationRequest');
    foreach($results as $result) {
        $sender = $result->sender;
        if ($testmode) {
            $sender = $DCC_CFG->testsender;
            $connectorid = $DCC_CFG->testsender;
        }
        if (count($result->recipients) == 1 and $result->recipients[0]->id == $connectorid) {
            $hash = '';
            if (isset($result->attachments) and count($result->attachments) == 1) { // If we have an attachment
                $fileid = $result->attachments[0]->id;
                $file_data = callAPI('GET', $host.'/api/v1/Files/'.$fileid.'/Download', false, $xapikey);
                $originalfilename = $result->attachments[0]->filename;
                if (substr($originalfilename, strlen($originalfilename) - strlen('.bcrt')) == '.bcrt' or
                    $result->attachments[0]->mimetype == 'application/json') { // Is the attachment a bcrt/json file?
                        $hash = calculate_hash($file_data);
                } else if ($result->attachments[0]->mimetype == 'application/pdf') { // Is the attachment a pdf file?
                    $filename = './temp/'.uniqid();
                    file_put_contents($filename, $file_data);
                    if ($json = detach_json($filename)) {
                        $hash = calculate_hash($json);
                    }
                    unlink($filename);
                }
            } else {
                $hash = $result->content->hash;
            }
            if (revoke_certificate($hash, $result->content->pk)) {
                $sendresult = send_request_result($sender,
                                                  $result->content->id,
                                                  'CertificateRevocationResult',
                                                  'false',
                                                  $host,
                                                  $xapikey);
                $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateRevocationRequest', 'false', $sendresult);
            } else {
                $sendresult = send_request_result($sender,
                                                  $result->content->id,
                                                  'CertificateRevocationResult',
                                                  'true',
                                                  $host,
                                                  $xapikey);
                $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateRevocationRequest', 'true', $sendresult);
            }
        } else {
            $sendresult = send_request_result($sender,
                                              $result->content->id,
                                              'CertificateRevocationResult',
                                              'true',
                                              $host,
                                              $xapikey);
            $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateRevocationRequest', 'true', $sendresult);
        }
        $last = $resultarray[array_key_last($resultarray)];
        log_received_request($result->id, 'CertificateRevocationRequest', $result->arrivedInMessages, $last->valid);
    }
    return $resultarray;
}

/*
{
  "recipients": [
    "MO1CBzRP20QfIcv4lFmw"
  ],
  "content":  {
    "type":"CertificateStorageRequest",
    "id":"15",
    "hash":"0x4b83fbe94ce2e141663ab64029b80c36a5ad8f2409ea26e8136b7970b61700c7",
    "startdate":"1606125262",
    "enddate":"0",
    "pk":"E31219B838B81B8FB3D84C54BD6E994DC7A08F91D1E343222E2E0051A72D9EEF"
  },
    "attachments": [
        "FILfaVvc7ytTH2CnbfIS"
    ]
}
*/
function check_certificate_storage_requests($connectorid, $xapikey, $host, $testmode = false) {
    global $DCC_CFG;
    $get_data = callAPI('GET', $host.'/api/v1/Messages', false, $xapikey);
    $dataobject = json_decode($get_data);
    $resultarray = array();
    $results = get_new_messages($dataobject->result, 'CertificateStorageRequest');
    foreach($results as $result) {
        $sender = $result->sender;
        if ($testmode) {
            $sender = $DCC_CFG->testsender;
            $connectorid = $DCC_CFG->testsender;
        }
        if (count($result->recipients) == 1 and $result->recipients[0]->id == $connectorid) {
            if (isset($result->content->hash) and $result->content->hash != '') { // If we have only the hash
                $hashes = store_certificate($result->content->hash,
                                            $result->content->startdate,
                                            $result->content->enddate,
                                            $result->content->pk);
            } elseif (isset($result->attachments) and count($result->attachments) == 1) { // If we have an attachment
                /*
                // TODO: 
                // While storing certificate in blockchain, some more informations need to be added to metadata
                // We can only receive a bcrt/json file and output a new file with added informations as result
                // maybe we can also generate a pdf
                $fileid = $result->attachments[0]->id;
                $file_data = callAPI('GET', $host.'/api/v1/Files/'.$fileid.'/Download', false, $xapikey);
                $originalfilename = $result->attachments[0]->filename;
                if (substr($originalfilename, strlen($originalfilename) - strlen('.bcrt')) == '.bcrt' or
                    $result->attachments[0]->mimetype == 'application/json') { // Is the attachment a bcrt/json file?
                        // TODO add info $file_data
                        $hash = calculate_hash($file_data);
                        $hashes = store_certificate($hash,
                                            $result->content->startdate,
                                            $result->content->enddate,
                                            $result->content->pk);
                }
                */
            }
            // Has certificate been successfully stored?
            if (isset($hashes->txhash)) {
                $sendresult = send_request_result($sender,
                                                $result->content->id,
                                                'CertificateStorageResult',
                                                'true',
                                                $host,
                                                $xapikey);
                $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateStorageRequest', 'true', $sendresult);
            } else {
                $sendresult = send_request_result($sender,
                                                $result->content->id,
                                                'CertificateStorageResult',
                                                'false',
                                                $host,
                                                $xapikey);
                $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateStorageRequest', 'false', $sendresult);
            }
        } else {
            $sendresult = send_request_result($sender,
                                              $result->content->id,
                                              'CertificateStorageResult',
                                              'false',
                                              $host,
                                              $xapikey);
            $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateStorageRequest', 'false', $sendresult);
        }
        // log when request is already replied
        $last = $resultarray[array_key_last($resultarray)];
        log_received_request($result->id, 'CertificateStorageRequest', $result->arrivedInMessages, $last->valid);
    }
    return $resultarray;
}

/*
{
	"recipients": [
		"MO1CBzRP20QfIcv4lFmw"
	],
	"content": {
		"type": "CertificateValidationRequest",
		"id": "31"
	},
	"attachments": [
		"FILfaVvc7ytTH2CnbfIS"
	]
}
*/
function check_certificate_validation_requests($connectorid, $xapikey, $host, $verbose = true, $testmode = false) {
    global $DCC_CFG;
    $get_data = callAPI('GET', $host.'/api/v1/Messages', false, $xapikey);
    $dataobject = json_decode($get_data);
    $resultarray = array();
    $results = get_new_messages($dataobject->result, 'CertificateValidationRequest');
    foreach($results as $result) {
        $sender = $result->sender;
        if ($testmode) {
            $sender = $DCC_CFG->testsender;
            $connectorid = $DCC_CFG->testsender;
        }
        if (count($result->recipients) == 1 and $result->recipients[0]->id == $connectorid) {
            // Check if hash or attachment.
            if (isset($result->content->hash) and $result->content->hash != '') {
                $cert = get_certificate($result->content->hash);
                $valid = boolval($cert->valid) ? 'true' : 'false';
                $sendresult = send_request_result($sender,
                                                    $result->content->id,
                                                    'CertificateValidationResult',
                                                    $valid,
                                                    $host,
                                                    $xapikey);
                $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateValidationRequest', $valid, $sendresult);
            } else if (isset($result->attachments) and count($result->attachments) == 1) {
                $fileid = $result->attachments[0]->id;
                $file_data = callAPI('GET', $host.'/api/v1/Files/'.$fileid.'/Download', false, $xapikey);
                $originalfilename = $result->attachments[0]->filename;
                if (substr($originalfilename, strlen($originalfilename) - strlen('.bcrt')) == '.bcrt' or
                    $result->attachments[0]->mimetype == 'application/json') {
                        $hash = calculate_hash($file_data);
                        $cert = get_certificate($hash);
                        $valid = boolval($cert->valid) ? 'true' : 'false';
                        $sendresult = send_request_result($sender,
                                                    $result->content->id,
                                                    'CertificateValidationResult',
                                                    $valid,
                                                    $host,
                                                    $xapikey);
                        $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateValidationRequest', $valid, $sendresult);
                } else if ($result->attachments[0]->mimetype == 'application/pdf') {
                    $filename = './temp/'.uniqid();
                    file_put_contents($filename, $file_data);
                    if ($json = detach_json($filename)) {
                        $hash = calculate_hash($json);
                        $cert = get_certificate($hash);
                        $valid = boolval($cert->valid) ? 'true' : 'false';
                        $sendresult = send_request_result($sender,
                                                    $result->content->id,
                                                    'CertificateValidationResult',
                                                    $valid,
                                                    $host,
                                                    $xapikey);
                        $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateValidationRequest', $valid, $sendresult);
                    } else {
                        // invalid
                        $sendresult = send_request_result($sender,
                                                    $result->content->id,
                                                    'CertificateValidationResult',
                                                    'false',
                                                    $host,
                                                    $xapikey);
                        $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateValidationRequest', 'false', $sendresult);
                    }
                    unlink($filename);
                } else {
                    // Wrong file format.
                    $sendresult = send_request_result($sender,
                                                    $result->content->id,
                                                    'CertificateValidationResult',
                                                    'false',
                                                    $host,
                                                    $xapikey);
                    $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateValidationRequest', 'false', $sendresult);
                }
            } else {
                // Hash or attachment is missing. When trying to verify a file we need exactly 1 attachment.
                $sendresult = send_request_result($sender,
                                                    $result->content->id,
                                                    'CertificateValidationResult',
                                                    'false',
                                                    $host,
                                                    $xapikey);
                $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateValidationRequest', 'false', $sendresult);
            }
        } else {
            // Number of recipients is not 1 or recipient is not the correct connector id.;
            $sendresult = send_request_result($sender,
                                                    $result->content->id,
                                                    'CertificateValidationResult',
                                                    'false',
                                                    $host,
                                                    $xapikey);
            $resultarray[] = get_checked_request($sender, $result->content->id, 'CertificateValidationRequest', 'false', $sendresult);
        }
        // erst loggen wenn request beantwortet wurde
        $last = $resultarray[array_key_last($resultarray)];
        log_received_request($result->id, 'CertificateValidationRequest', $result->arrivedInMessages, $last->valid);
    }
    return $resultarray;
}

/*
{
  "recipients": [
    "SPudUZVfWejeAAc7YYX1"
  ],
  "content":  {
    "type":"CertificateValidationResult",
    "requestid":"1",
    "valid":"true"
  }
}
*/
function send_request_result($recipient, $requestid, $type, $valid, $host, $xapikey) {
    $data = new stdClass();
    $data->recipients = array($recipient);
    $data->content = new stdClass();
    $data->content->type = $type;
    $data->content->requestid = $requestid;
    $data->content->valid = $valid;
    return callAPI('POST', $host.'/api/v1/Messages', json_encode($data), $xapikey);
}

function get_checked_request($from, $requestid, $type, $valid, $sendresult = '') {
    $result = new stdClass();
    $result->from = $from;
    $result->requestid = $requestid;
    $result->type = $type;
    $result->valid = $valid;
    $result->timecreated = date('d.m.Y - H:i:s', time());
    if ($sendresult != '') {
        $sr = json_decode($sendresult);
        if (isset($sr->error)) {
            $result->sendresult = $sr->error->code;
        } else {
            $result->sendresult = 'ok';
        }
    }
    return $result;
}

function detach_json($filename) {
    // Get Attachments.
    $attachmentlistresult = shell_exec('pdfdetach -list '.$filename.' 2>&1');
    $attachmentlist = explode("\n", $attachmentlistresult);
    $attachments = array();
    $n = 0;
    foreach ($attachmentlist as $attachment) {
        if ($n == 0) {
            $n++;
            continue;
        }

        if ($attachment == "") {
            continue;
        }

        $entry = explode(': ', $attachment);
        $key = $entry[0];
        $value = $entry[1];
        $attachments[$key] = $value;
    }
    // More than 1 attachment? -> error.
    if (count($attachments) != 1) {
        return false;
    } else {
        $attachmentfile = array_pop($attachments);
        $basenameattachmentfile = basename($attachmentfile).'_'.uniqid();
        $path = './temp/'.$basenameattachmentfile;
        shell_exec('pdfdetach -save 1 -o '.$path.' '.$filename.' 2>&1');
        if (!isset($detachresult)) {
            $filecontent = file_get_contents($path);
            unlink($path);
            // Return metadata as json.
            return $filecontent;
        } else {
            return false;
        }
    }
}

function sort_obj($obj) {
    $arr = array();
    $sortedobj = new stdClass();

    if (is_object($obj)) {
        foreach ($obj as $key => $value) {
            $arr[$key] = sort_obj($value);
        }
        ksort($arr);
        foreach ($arr as $key => $value) {
            $sortedobj->$key = $value;
        }
    } else if (is_array($obj)) {
        $sortedobj = $obj;
    } else if (is_string($obj)) {
        $sortedobj = $obj;
    }
    return $sortedobj;
}

function calculate_hash($metadatajson) {
    $metadatajson = json_decode($metadatajson);
    $metadatajson = sort_obj($metadatajson);
    $metadatajson->recipient->hashed = false;
    // Remove verification (if exists already).
    unset($metadatajson->{'extensions:verifyB4E'});
    unset($metadatajson->{'verification'}); // For downward compatibility.
    $metadatajson = json_encode($metadatajson, JSON_UNESCAPED_SLASHES);
    $hash = '0x'.hash('sha256', $metadatajson);
    return $hash;
}