#!/usr/bin/php
<?php
/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Niedersächsische Staats- und Universitätsbibliothek Göttingen
 * 	Jochen Kothe <kothe@sub.uni-goettingen.de>
 * 	Thomas Fischer <fischer@sub.uni-goettingen.de>
 *  All rights reserved
 *
 *  This script free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * ************************************************************* */

// PHP-script to collect data from OAI-PMH servers
// Configuration:
// ******************************************************
set_time_limit(0);            // no time limit
ini_set('memory_limit', '512M');     // Moderate memory limit:
error_reporting(0);           // Disable Error-Messages
$repeat = 5;              // Number of attempts
$rounds = 0;              // Initialize count of rounds
$splitfiles = false;          // split OAI files into separate records?
$testFile = '/RemoteDesk/DomTest.xml';  // you can provide a file to test the working of the script
$defaultDir = '/RemoteDesk/GDZ/';    // default directory to collect the OAI result in, they will accumulate in subdirectories
// ******************************************************
/*
  Synopsis: php <path-to-this-script> <OAI-URL> <verb> <targetdir> [<key>=<val>]
  command call: php usually not necessary
  Parameters:
  path-to-this-script: obvious
  OAI-URL: the URL of the OAI data provider, up to but not including the question mark
  If you don't provide a URL (and nothing else), the script will run in testmode using the $testFile and the verb ListIdentifier
  verb: OAI-MPH command, one of Identify, ListMetadataFormats, ListSets, GetRecord, ListIdentifiers, ListRecords
  Identify: basic information on the OAI data provider
  ListMetadataFormats: collect a lists of all available metadata formats,
  probably not available for all sets or records
  ListSets: collect a listing of the sets offered by the OAI-Provider
  GetRecord: retrieve a single record, determined by its identifier
  ListIdentifiers: collect a listing of the identifiers of all datasets
  ListRecords: collect all datasets in XML files according to the provisions by the OAI data provider
  targetdir = directory to collect the data in, will be created if possible and not present.
  If missing, the $defaultDir will be used. To distinguish it from keys, targetdir must not include "=".
  <key>=<val> = additional parameters
  Note that according to the OAI protocol the admissibility of additional parameters depends on the chosen verb!
  For Identify, ListMetadataFormats and ListSets, additional parameters are ignored,
  for other parameters the user is on his of her own (and obliged to follow the OAI-PMH).
  For Sets ("set=…") several sets have to be separated by ';' (no space),
  sets are separately collected in respective subdirectories.
  Continuous feedback on the result is provided;
  in case of of failure the action is repeated up to "$repeat" times;
  OAI errors will be reported and the execution of the script is aborted.

  Example:
  php ./oai_harvest.php http://gdz.sub.uni-goettingen.de/oai2/ ListRecords ./gdzNeu/ metadataPrefix=mets set=mathematica  from=2011-05-10
  Example for a call using an Internet browser:
  http://gdz.sub.uni-goettingen.de/oai2/?verb=GetRecord&metadataPrefix=oai_dc&identifier=gdz.sub.uni-goettingen.de:PPN623147521

  Note: Some of the calls, in particular ListRecords, may need a broadband internet connection and
  still may take a long time (up to hours) and collect large amounts of data (Gigabytes!).
  So beware and be patient, watch the output to observe if the call does what you want!
  Note: The files are downloaded as <verb>.xml and then copied to another name with tags on separate lines.
  In particular, the last file is always present as <verb>.xml and in an edited version.
  If this is undesirable then comment the line: file_put_contents($targetdir.$theVerb.'.xml',$data);
 */

list($arrArgs, $arrArgs2, $targetdir, $sets, $theExtension, $testmode) = initialize($defaultDir, $testFile);

$errorlog = $targetdir . 'error.log';
$harvestlog = $targetdir . 'harvest.log';
$theBaseUrl = $arrArgs['oaiURL'];
$theVerb = $arrArgs['verb'];
if (!$testmode)
    $theBaseUrl .='?verb=' . $theVerb;

if (!$errorHandle = fopen($errorlog, 'a')) {
    echo $errorlog . ' is not writeable!' . "\n";
    exit();
}

if (!$logHandle = fopen($harvestlog, 'a')) {
    echo $harvestlog . ' is not writeable!' . "\n";
    exit();
}
echo "BaseURL: $theBaseUrl\n";
echo "Target folder: $targetdir\n";
echo "Logs: $harvestlog and $errorlog\n";

// main loop to collect data
if ($sets) {
    echo "Sets defined!\n";
    $baseTarget = $targetdir;
    foreach ($sets as $set) {
        $setName = strtr($set, '/:\\', '---');
        $targetdir = $baseTarget . $setName . '/';
        if (!is_dir($targetdir))
            mkdir($targetdir, 0775);
        $theExtension = getOAI($theBaseUrl, $theExtension . '&set=' . $set, $theVerb);
        while ($theExtension) {
            $theExtension = getOAI($theBaseUrl, '&resumptionToken=' . $theExtension, $theVerb);
        }
    }
} else {
    $theExtension = getOAI($theBaseUrl, $theExtension, $theVerb);
    while ($theExtension) {
        $theExtension = getOAI($theBaseUrl, '&resumptionToken=' . $theExtension, $theVerb);
    }
}

fclose($errorHandle);
fclose($logHandle);
exit;

function initialize($defaultDir, $testFile) {
    global $splitfiles;
    $arrArgs['script'] = array_shift($_SERVER["argv"]);

    $arrArgs['oaiURL'] = array_shift($_SERVER["argv"]);
    if (!isset($arrArgs['oaiURL'])) {
        $arrArgs['oaiURL'] = $testFile;
        $testmode = true;
        echo "Testmode!\n";
    } else
        $testmode = false;

    $arrArgs['verb'] = array_shift($_SERVER["argv"]);
    if (!isset($arrArgs['verb'])) {
        $arrArgs['verb'] = 'ListRecords';
    }

    $targetdir = $arrArgs['targetDIR'] = array_shift($_SERVER["argv"]);
    if (strpbrk($targetdir, "=")) {
        array_unshift($_SERVER["argv"], $targetdir);
        unset($targetdir);
    }
    if (!isset($targetdir)) {
        $targetdir = $defaultDir;
    }
    if (!strpos($targetdir, '/', strlen($targetdir) - 1))
        $targetdir .= '/'; // '/' am Ende des Ordners sicherstellen

    foreach ($_SERVER["argv"] as $para) {
        list($key, $val) = explode('=', $para, 2);
        $arrArgs2[$key] = $val;
    }

    if (!is_dir($targetdir) && !mkdir($targetdir, 0777, true)) {
        echo 'Could not create directory ' . $targetdir . "!\n";
        exit;
    }
    if (!opendir($targetdir)) {
        echo $targetdir . " is not readable!\n";
        exit;
    }
    if (!touch($targetdir . 'tmp')) {
        echo $targetdir . " is not writeable!\n";
        exit;
    } else {
        unlink($targetdir . 'tmp');
    }
// 	only OAI verbs are admissible:
    $arrVerbs = array('Identify', 'ListSets', 'ListMetadataFormats', 'GetRecord', 'ListIdentifiers', 'ListRecords');
    $theVerb = $arrArgs['verb'];
    if (!in_array($theVerb, $arrVerbs)) {
        echo "wrong verb: $theVerb!\n";
        exit;
    }

    if (array_key_exists('resumptionToken', $arrArgs2))
        $theExtension = '&resumptionToken=' . $arrArgs2['resumptionToken'];
    elseif (in_array($theVerb, array("Identify", "ListMetadataFormats", "ListSets"))) {
        $theExtension = '';
        if ($splitfiles) {
            echo "splitfiles is not supported for $theVerb\n";
            $splitfiles = false;
        }
    } else {
        if (!array_key_exists('metadataPrefix', $arrArgs2))
            $arrArgs2['metadataPrefix'] = 'oai_dc'; // default oai_dc
        if (array_key_exists('set', $arrArgs2)) {
            $sets = explode(';', $arrArgs2['set']);
            unset($arrArgs2['set']);
        } else
            $sets = "";
        $theExtension = '';
        foreach ($arrArgs2 as $key => $val)
            $theExtension .= '&' . $key . '=' . $val;
    }
    return array($arrArgs, $arrArgs2, $targetdir, $sets, $theExtension, $testmode);
}

function getOAI($theBaseUrl, $theExtension, $theVerb) {
    global $rounds, $errorlog, $testmode, $splitfiles, $targetdir, $logHandle;
    $rounds++;
    echo 'Round ' . $rounds . "\n";
    $time_start = microtime_float();
    $url = $theBaseUrl . $theExtension;
    if ($testmode)
        $xml = file_get_contents($theBaseUrl); // test the script using available file
    else
        $xml = getXML($url, $theVerb);
    message('Collecting ' . $url, false);
    echo 'Data received: ' . strlen($xml) . " Bytes.\n";
    // build DOM
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml)) {
        message('could not build DOM for ' . $url, true);
        file_put_contents($targetdir . 'error.xml', $xml);
        return;
    }
    // get error
    $errorNode = $dom->getElementsByTagName('error');
    if ($errorNode->length) {
        echo "Error encountered!\n";
        $error = $errorNode->item(0)->nodeValue;
        if ($error) {
            message('retrieving ' . $url . ":\n" . $error, true);
            return;
        }
    }
    // get responseDate
    $node = $dom->getElementsByTagName('responseDate');
    if ($node->length) {
        $responseDate = $node->item(0)->nodeValue;
        echo "responseDate: $responseDate\n";
    }
    // retrieve request
    $requests = $dom->getElementsByTagname('request');
    if ($requests->length) {
        $request = $requests->item(0);
        $fullRequest = $request->nodeValue . '?';
        if ($request->hasAttribute('verb'))
            $fullRequest .= 'verb=' . $request->getAttribute('verb');
        if ($request->hasAttribute('metadataPrefix'))
            $fullRequest .= '&metadataPrefix=' . $request->getAttribute('metadataPrefix');
        if ($request->hasAttribute('set'))
            $fullRequest .= '&set=' . $request->getAttribute('set');
        echo "request: $fullRequest\n";
    }
    fwrite($logHandle, 'Request: ' . $url . "\rDate: " . $responseDate . "\r");
    // rewrite file with instructive name and separated lines (better for grep!)
    if (!$splitfiles) {
        $fullRequest = str_replace('metadataPrefix', 'mP', $fullRequest);
        $fullRequest = str_replace('http://', '', $fullRequest);
        $fullRequest = str_replace('ListRecords', 'LR', $fullRequest);
        $fullRequest = str_replace('ListIdentifiers', 'LI', $fullRequest);
        $theFilename = strtr(trim($fullRequest), '/:', '_-') . '-' . $rounds . '.xml';
        $xml = str_replace('><', ">\n<", $xml);
        file_put_contents($targetdir . $theFilename, $xml);
    } else
        $test = writeXML($dom, $url); //auf Platte schreiben
    echo "XML written!\n";

    //get resumptionToken
    $node = $dom->getElementsByTagName('resumptionToken');
    if ($node->length) {
        $theExtension = $node->item(0)->nodeValue;
        return $theExtension;
    } else
        return "";
}

function microtime_float() {
    list($usec, $sec) = explode(" ", microtime());
    return ((float) $usec + (float) $sec);
}

function getXML($theURL, $theVerb) {
    global $errorlog, $targetdir, $repeat;
    $count = 0;
    $data = '';
    while (!$data) {
        if ($count > $repeat) {
            print_r('Give up!' . "\n");
            message('not retrieved: ' . $theURL, true);
            exit;
        }
        $count++;
        print_r($count . '. attempt: ' . $theURL . "\n");
        $theTime = microtime(true);
        $data = file_get_contents($theURL);
        $theTime2 = microtime(true);
    }
    echo ($theTime2 - $theTime) . " secs needed.\n";
    file_put_contents($targetdir . $theVerb . '.xml', $data);
    return $data;
}

function writexml($dom, $url) {
    global $arrArgs, $arrArgs2, $targetdir, $logHandle, $errorhandle;
    echo 'writexml for ' . $url . "\n";
    if ($arrArgs['verb'] == 'ListRecords') {
        $recordList = $dom->getElementsByTagname('record');
        echo 'writexml: record list: ' . $recordList->length . " elements\n";
        if (!$recordList->length) {
            message('no records found for ' . $url, true);
            return;
        }
        foreach ($recordList as $record) {
            // create new file
            $oaiID = $record->getElementsByTagname('identifier')->item(0)->nodeValue;
            $fileName = strtr(trim($oaiID), '/:', '_-');
            $i = 0;
            $theSets = array();
            while ($newSet = $record->getElementsByTagname('setSpec')->item($i)) {
                array_push($theSets, $newSet->nodeValue);
                $i++;
            }
            $theFileName = getFolder($theSets);
            $theFileName .= $fileName . '.xml';
            echo 'New file: ' . $theFileName . "\n";
            if (is_file($theFileName))
                continue;
            printMetadata($record, $theFileName, $oaiID);
            unset($theFileName);
            unset($oaiID);
        }
        return;
    }
    if ($arrArgs['verb'] == 'ListIdentifiers') {
        $idList = $dom->getElementsByTagname('identifier');
        if (!$idList->length)
            return;
        $urlBase = $arrArgs['oaiURL'] . '?verb=GetRecord&metadataPrefix=' . $arrArgs2['metadataPrefix'] . '&identifier=';
        foreach ($idList as $id) {
            $theFileName = $targetdir . strtr(trim($id->nodeValue), '/:', '_-') . '.xml';
            if (is_file($theFileName))
                continue;
            $recordxml = getXML($urlBase . trim($id->nodeValue));
            $record = new DOMDocument();
            $record->loadXML($recordxml);
            $metadataList = $record->getElementsByTagname('metadata');
            if ($metadataList->length) {
                $metadata = $metadataList->item(0);
                printMetadata($metadata, $theFileName, $id->nodeValue);
                unset($metadata);
            } else {
                message('no record found ' . $id->nodeValue, true);
            };
        }
    }
}

function getFolder($theSets) {
    global $targetdir, $arrArgs2;
    static $setFolders = array();
    foreach ($theSets as $set) {
        if (array_key_exists($set, $setFolders))
            return $setFolders[$set];
        if ($set == $arrArgs2['set'])
            continue;
        $setName = strtr($set, '/:\\', '---');
        $theFolder = $targetdir . $setName . '/';
        if (!is_dir($theFolder))
            mkdir($theFolder, 0775);
        echo 'New set: ' . $set . "!\n";
        $setFolders[$set] = $theFolder;
        return $theFolder;
    }
    return $targetdir;
}

function printMetadata($metadata, $theFileName, $theID) {
    global $targetdir, $errorlog;
    $list = $metadata->childNodes;
    if (!$list->length) {
        message('no metadata found ' . $theID, true);
        return;
    }
    $mets = new DOMDocument();
// 	import whole record
    $import = $mets->importNode($metadata, true);
    $mets->appendChild($import);
// 	foreach($list as $tmpNode) {
// 		$import = $mets->importNode($tmpNode,true);
// 		$mets->appendChild($import);
// // 			If($tmpNode->nodeType=1) break;
// 	}
// 	$mets->str_replace('><',">\n<",$this);
// 	$data = $mets->htmlDumpMem();
// 	$mets->dump_file($theFileName,false,true);
    $mets->save($theFileName);
    Echo "Dumped!\n";
    unset($mets);
    unset($tmpNode);
}

function message($theMessage, $error) {
    global $harvestlog, $errorlog, $errorHandle, $logHandle;
    date_default_timezone_set('Europe/Berlin');
    if ($error) {
        fwrite($errorHandle, 'Error (' . date('d.m.Y H:i:s') . '): ' . $theMessage . "\n");
        echo 'ERROR: ' . $theMessage . "\n";
    } else {
        fwrite($logHandle, $theMessage . ' (' . date('d.m.Y H:i:s') . ")\n");
        echo $theMessage . "\n";
    }
}
?>
