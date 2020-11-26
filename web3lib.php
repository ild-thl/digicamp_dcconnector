<?php
/**
 * Library of functions to interact with blockchain.
 *
 * @copyright   2020 ILD TH Lübeck <dev.ild@th-luebeck.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('vendor/autoload.php');
require_once('config.php');

use Web3\Web3;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3\Contract;
use Web3p\EthereumTx\Transaction;
use Web3p\EthereumUtil\Util;
//use Web3\Contracts\Types\Bytes;
//use phpseclib\Math\BigInteger;

global $account;
global $contractnames;
$contractnames = array('CertMgmt'       => 'CertificateManagement',
                        'IdentityMgmt' => 'IdentityManagement');

function check_node($url) {
    $web3 = new Web3(new HttpProvider(new HttpRequestManager($url, 30)));
    $success = false;
    $web3->eth->blockNumber(function ($err, $blocknumber) use (&$success) {
        if ($err === null) {
            $block = $blocknumber->value;
            if ($block > 0) {
                $success = true;
            }
        }
    });
    return $success;
}

function get_contract_abi($contractname) {
    global $contractnames;
    $contractname = $contractnames[$contractname];
    $filename = './contracts/'.$contractname.'.json';
    $contract = json_decode(file_get_contents($filename));
    return json_encode($contract->contract_abi);
}

function get_contract_address($contractname) {
    global  $contractnames;
    $contractname = $contractnames[$contractname];

    $filename = './contracts/'.$contractname.'.json';
    $contract = json_decode(file_get_contents($filename));
    return $contract->contract_address;
}

function get_contract_url($contractname) {
    global $DCC_CFG, $contractnames;
    $contractname = $contractnames[$contractname];

    $blockchainurl = $DCC_CFG->blockchainurl;
    $failoverurl = $DCC_CFG->failoverurl;
    // Check if node is working.
    if (isset($blockchainurl) and $blockchainurl != '' and check_node($blockchainurl)) {
        return $blockchainurl;
    } else if (isset($failoverurl) and $failoverurl != '' and check_node($failoverurl)) {
        return $failoverurl;
    } else {
        return false;
    }
}

function store_certificate($hash, $startdate, $enddate, $pk) {
    global $DCC_CFG;
    $url = get_contract_url('CertMgmt');
    $account = get_address_from_pk($pk);
    $contractabi = get_contract_abi('CertMgmt');
    $contractadress = get_contract_address('CertMgmt');
    $storehash = $hash;
    $chainid = $DCC_CFG->chainid;

    $web3 = new Web3(new HttpProvider(new HttpRequestManager($url, 30)));
    $eth = $web3->eth;

    $contract = new Contract($web3->provider, $contractabi);
    $contract->at($contractadress);

    $hashes = new stdClass();
    $hashes->certhash = $hash;

    $nonce = 0;
    $r = $eth->getTransactionCount($account, 'latest', function ($err, $data)  use (&$nonce) {
        if ($err !== null) {
            throw $err;
        }
        $nonce = $data->toString();
    });

    $functiondata = $contract->getData('storeCertificate', $storehash, $startdate, $enddate);

    $transaction = new Transaction(array(
            'from' => $account,
            'nonce' => '0x'.dechex($nonce),
            'to' => $contractadress,
            'gas' => dechex(450),
            'data' => '0x'.$functiondata,
            'chainId' => $chainid
    ));
    $signedtransaction = $transaction->sign($pk);

    $eth->sendRawTransaction('0x'.$signedtransaction, function ($err, $tx) use (&$hashes){
        if ($err !== null) {
            throw $err;
        }
        $hashes->txhash = $tx;
    });

    // Check if exists.
    $start = time();
    while (1) {
        $now = time();
        $cert = get_certificate($hashes->certhash);
        if (isset($cert->valid) and $cert->valid == 1) {
            return $hashes;
        }
        if ($now - $start > 30) {
            unset($hashes->certhash);
            unset($hashes->txhash);
            break;
        }
    }
    return $hashes;
}

function revoke_certificate($certhash, $pk) {
    global $DCC_CFG;
    $url = get_contract_url('CertMgmt');
    $account = get_address_from_pk($pk);
    $contractabi = get_contract_abi('CertMgmt');
    $contractadress = get_contract_address('CertMgmt');
    $chainid = $DCC_CFG->chainid;

    $web3 = new Web3(new HttpProvider(new HttpRequestManager($url, 30)));
    $eth = $web3->eth;

    $contract = new Contract($web3->provider, $contractabi);
    $contract->at($contractadress);

    $nonce = 0;
    $r = $eth->getTransactionCount($account, 'latest', function ($err, $data)  use (&$nonce) {
        if ($err !== null) {
            throw $err;
        }
        $nonce = $data->toString();

    });

    $functiondata = $contract->getData('revokeCertificate', $certhash);

    $transaction = new Transaction(array(
            'from' => $account,
            'nonce' => '0x'.dechex($nonce),
            'to' => $contractadress,
            'gas' => dechex(450),
            'data' => '0x'.$functiondata,
            'chainId' => $chainid
    ));
    $signedtransaction = $transaction->sign($pk);

    $eth->sendRawTransaction('0x'.$signedtransaction, function ($err, $tx) {
        if ($err !== null) {
            throw $err;
        }
    });

    $cert = get_certificate($certhash);
    
    $start = time();
    while (1) {
        $now = time();
        $cert = get_certificate($certhash);
        if (isset($cert->valid) and $cert->valid != 1) {
            return true;
        }
        if ($now - $start > 30) {
            break;
        }
    }
    return false;
}

function get_certificate($certhash) {
    $cert = new stdClass();

    $web3 = new Web3(new HttpProvider(new HttpRequestManager(get_contract_url('CertMgmt'), 30)));

    $contract = new Contract($web3->provider, get_contract_abi('CertMgmt'));
    $contract->at(get_contract_address('CertMgmt'));
    $contract->call('getCertificate', $certhash, function ($err, $result) use ($cert) {
        if ($err !== null) {
            throw $err;
        }
        if ($result) {
            $cert->institution = $result[2];
            $cert->institutionProfile = $result[3];
            $cert->startingDate = $result[4][0]->value;
            $cert->endingDate = $result[4][1]->value;
            $cert->onHold = $result[5]->value;
            $cert->valid = $result[6];
        }
    });
    return $cert;
}

function add_certifier_to_blockchain($useraddress, $adminpk) {
    global $DCC_CFG;
    $url = get_contract_url('IdentityMgmt');
    $account = get_address_from_pk($adminpk);
    $contractabi = get_contract_abi('IdentityMgmt');
    $contractadress = get_contract_address('IdentityMgmt');
    $chainid = $DCC_CFG->chainid;

    $web3 = new Web3(new HttpProvider(new HttpRequestManager($url, 30)));
    $eth = $web3->eth;

    $contract = new Contract($web3->provider, $contractabi);
    $contract->at($contractadress);

    $nonce = 0;
    $r = $eth->getTransactionCount($account, 'latest', function ($err, $data)  use (&$nonce) {
        if ($err !== null) {
            throw $err;
        }
        $nonce = $data->toString();
    });

    $functiondata = $contract->getData('registerCertifier', $useraddress);

    $transaction = new Transaction(array(
            'from' => $account,
            'nonce' => '0x'.dechex($nonce),
            'to' => $contractadress,
            'gas' => dechex(450),
            'data' => '0x'.$functiondata,
            'chainId' => $chainid
    ));

    $signedtransaction = $transaction->sign($adminpk);

    $eth->sendRawTransaction('0x'.$signedtransaction, function ($err, $tx) {
        if ($err !== null) {
            throw $err;
        }
    });
}

function is_accredited_certifier($address) {
    $certifier = false;

    $web3 = new Web3(new HttpProvider(new HttpRequestManager(get_contract_url('IdentityMgmt'), 30)));

    $contract = new Contract($web3->provider, get_contract_abi('IdentityMgmt'));
    $contract->at(get_contract_address('IdentityMgmt'));
    $contract->call('isAccreditedCertifier', $address, function ($err, $result) use (&$certifier) {
        if ($err !== null) {
            throw $err;
        }
        if ($result) {
            $certifier = $result[0];
        }
    });
    return $certifier;
}

function remove_certifier_from_blockchain($useraddress, $adminpk) {
    global $DCC_CFG;
    $url = get_contract_url('IdentityMgmt');
    $account = get_address_from_pk($adminpk);
    $contractabi = get_contract_abi('IdentityMgmt');
    $contractadress = get_contract_address('IdentityMgmt');
    $chainid = $DCC_CFG->chainid;

    $web3 = new Web3(new HttpProvider(new HttpRequestManager($url, 30)));
    $eth = $web3->eth;

    $contract = new Contract($web3->provider, $contractabi);
    $contract->at($contractadress);

    $nonce = 0;
    $r = $eth->getTransactionCount($account, 'latest', function ($err, $data)  use (&$nonce) {
        if ($err !== null) {
            throw $err;
        }
        $nonce = $data->toString();
    });
    $functiondata = $contract->getData('removeCertifier', $useraddress);

    $transaction = new Transaction(array(
            'from' => $account,
            'nonce' => '0x'.dechex($nonce),
            'to' => $contractadress,
            'gas' => dechex(450),
            'data' => '0x'.$functiondata,
            'chainId' => $chainid
    ));
    $signedtransaction = $transaction->sign($adminpk);

    $eth->sendRawTransaction('0x'.$signedtransaction, function ($err, $tx) {
        if ($err !== null) {
            throw $err;
        }
    });
}

function get_address_from_pk($pk) {
    $util = new Util();
    $publickey = $util->privateKeyToPublicKey($pk);
    $address = $util->publicKeyToAddress($publickey);
    return $address;
}

function get_institution_from_certifier($address) {
    $institutionaddress = false;

    $web3 = new Web3(new HttpProvider(new HttpRequestManager(get_contract_url('IdentityMgmt'), 30)));

    $contract = new Contract($web3->provider, get_contract_abi('IdentityMgmt'));
    $contract->at(get_contract_address('IdentityMgmt'));
    $contract->call('getInstitutionFromCertifier', $address, function ($err, $result) use (&$institutionaddress) {
        if ($err !== null) {
            throw $err;
        }
        if ($result) {
            $institutionaddress = $result[0];
        }
    });
    return $institutionaddress;
}

function get_pending_transactions() {
    $url = get_contract_url('IdentityMgmt');
    $web3 = new Web3(new HttpProvider(new HttpRequestManager($url, 30)));
    $eth = $web3->eth;
    $eth->getPendingTransactions(function ($err, $data) {
        if ($err !== null) {
            throw $err;
        }
    });
}