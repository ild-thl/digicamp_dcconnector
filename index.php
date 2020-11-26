<?php
require_once('config.php');
require_once('dcc_lib.php');

echo '<script src="https://code.jquery.com/jquery-latest.js"></script>';

echo '<h1>DC Connector</h1>';

if (process_running() > 0) {
    echo '<p id="process-info" style="color:green;">Process is running</p>';
}
else {
    echo '<p id="process-info" style="color:red;">Process is not running</p>';
}

echo '<textarea id="dccconsole" style="margin: 0px;height: 137px;width: 1090px;" readonly></textarea>';

echo '<script src="./js/dcc.js"></script>';