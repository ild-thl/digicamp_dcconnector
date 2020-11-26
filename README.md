# DC Connector #

## Installation ##

`clone repository`

For interacting with the blockchain, you need to install the following extensions:
  * https://github.com/sc0Vu/web3.php
  * https://github.com/web3p/ethereum-tx
  
The file composer.json is already included in this repository. So simply:

`run "composer install" on command line`

For detaching metadata from PDF files, you need to install Poppler (https://poppler.freedesktop.org/)

`yum install poppler poppler-utils`

Rename config.php.dist to config.php and customise it or use default values

To start service

`run "php poll_messages.php 2>/dev/null >/dev/null &" on command line`