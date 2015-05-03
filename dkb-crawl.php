#!/usr/bin/php
<?php

chdir(__DIR__);
require('simple_html_dom.php');
require('config.php');

$url = 'https://banking.dkb.de';
define('CSV_HEADER_LINES', 6);
define('CSV_EC_COLUMN_DATE', 0);
define('CSV_EC_COLUMN_DATE2', 1);
define('CSV_EC_COLUMN_SUBJECT1', 3);
define('CSV_EC_COLUMN_SUBJECT2', 4);
define('CSV_EC_COLUMN_VALUE', 7);
define('CSV_CC_COLUMN_DATE', 2);
define('CSV_CC_COLUMN_SUBJECT', 3);
define('CSV_CC_COLUMN_VALUE', 4);
define('CSV_COLUMN_NAME_VALUE', 'Betrag (EUR)');
define('CSV_COLUMN_NAME_RECEIVER', 'Auftraggeber / Begünstigter');

function csvLineToArray($line)
{
	$data = explode(';', $line);
	$data = array_map(function($e){return trim($e, '" ><');}, $data);
	return $data;
}

function doCurlPost($action, $data) {
	global $url, $ch;
	
	$lastUri = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	if ($lastUri) { curl_setopt($ch, CURLOPT_REFERER, $lastUri); }

	curl_setopt($ch, CURLOPT_URL, $url . $action);
	curl_setopt($ch, CURLOPT_POST, count($data));
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	
	return curl_exec($ch);
}

function doCurlGet($path) {
	global $url, $ch;
	
	$lastUri = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	if ($lastUri) { curl_setopt($ch, CURLOPT_REFERER, $lastUri); }

	curl_setopt($ch, CURLOPT_URL, $path);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
	
	return curl_exec($ch);
}

function findLineInCSV($line, $csv) {
	foreach ($csv as $k => $v) {
		if ($k < CSV_HEADER_LINES) continue;
		if ($v == $line) {
			return $k;
		}
	}

	return false;
}

function getCsvFile($account)
{
	return __DIR__ . '/data/' . $account['nr'];
}

function processAccount($account)
{
	$cnt = 0;
	$file = getCsvFile($account);
	$lines = explode("\n", $account['csv']);

	$exists = file_exists($file);
	$csv = $exists? file($file, FILE_IGNORE_NEW_LINES) : false;
	file_put_contents($file, $account['csv']);
	if (!$exists)
	{
		// no push on first run. just save the csv for later comparison
		return;
	}
	
	$push = array();

	foreach ($lines as $k => $line) {
		if ($k < CSV_HEADER_LINES || !$line) continue;
		if ($k < (CSV_HEADER_LINES+1) || !$line)
		{
			$headers  = csvLineToArray($line);
			# print_r($headers);
			continue;
		}

		$columns = csvLineToArray($line);
		foreach ($headers as $i => $k)
		{
			$data[$k] = $columns[$i];
		}

		$lineNbr = findLineInCSV($line, $csv);
		if ($lineNbr === false)
		{
			// if (++$cnt >= 100) break; // no more than $maxMessages push messages per account per run
			
			$transaction = array(
				account => $account['desc'], 
				keys => $headers,
				data => $data,
			);
			
			# print_r($transaction);
			
			$push[] = $transaction;
		}
	}
	
	return $push;
}

function push($transactions)
{
	global $smtp, $from, $to;

	foreach ($transactions as $transaction)
	{
		$bodyLines = array();
		
		print_r($transaction);
		
		foreach ($transaction[keys] as $i => $k)
		{
			$bodyLines[] = $k . ': ' . $transaction[data][$k];
		}
		
		$body  = implode("\n", $bodyLines); 
	
		$headers = array(
			'From' => $from,
			'To' => $to,
			'Subject' => $transaction[account] . ": " . $transaction[data][CSV_COLUMN_NAME_VALUE] . " " . $transaction[data][CSV_COLUMN_NAME_RECEIVER],
		);
	
		echo 'Send ' . $headers[Subject] . "\n";
		$mail = $smtp->send($to, $headers, $body);
	}
	
		$headers = array(
			'From' => $from,
			'To' => $to,
			'Subject' => 'dkb-crawl.php complete',
		);

		$mail = $smtp->send($to, $headers, '');
}

function test()
{
$account = array(
	'nr' => '0016167710',
	'desc' => 'Kreditkarte',
);
	$account['csv'] = file_get_contents(GetCsvFile($account));
	# print_r($account);
	$ts  = processAccount($account);
	push($ts);
}

# test(); return;

//
// CURL init
//
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIESESSION, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, 'data/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEJAR, 'data/cookie.txt');
//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_CAINFO, 'cacert.pem');

//
// LOGIN
//
echo 'Logging in...';
$result = doCurlGet($url . '/dkb/-');
$dom = str_get_html($result);
$redirect = $dom->find('a', 0)->href;

$result = doCurlGet($redirect);
$dom = str_get_html($result);
$form = $dom->find('form', 0);

$post_data = array();
foreach ($form->find('input') as $elem) {	
	if ($elem->name == 'j_username') $elem->value = $kto;
	if ($elem->name == 'j_password') $elem->value = $pin;
	
	$post_data[$elem->name] = $elem->value;	
}
$html_ = doCurlPost($form->action, $post_data);

if (strpos($html_, 'Letzte Anmeldung:') !== false) {
	echo "OK!\n";
} else {
	echo 'Error. Login failed!';
	die();
}

//
// get Konten
//
echo "get Konten...\n";
$accounts = array();
$matches = array();

$dom_ = str_get_html($html_);
foreach ($dom_->find('table[class=financialStatusTable] tr') as $k => $row) {
	if (!$k || $row->class == 'sum bgColor') continue;
	// switch back to finanzstatus
	if ($k > 1) {
		$href = $dom_->find('a[id=menu_0]', 0)->href;
		doCurlGet($url . $href);
	}
	
	// loop
	$post_data = array();
	$td = $row->find('td', 0);
	if (!$td) continue;
	$nr = trim(strip_tags($td->find('strong', 0)->plaintext));
	$desc = trim($row->find('td', 1)->plaintext);
	echo "  found '$desc' ($nr)";
	$button = $row->find('td', 3)->find('a[class=evt-creditCardDetails]', 0);
	$ec = $button === NULL;
	if (false ) {
		// EC Card (POST)
		$ec = true;
		echo " - is EC";		
		$form = $dom_->find('form', 2);
		
		$e = $form->find('input[type=hidden]', 0);
		$post_data[$e->name] = $e->value;
		$post_data[$button->name] = $button->value;
		$html = doCurlPost($form->action, $post_data);

		// download CSV
		$post_data = array();
		echo " - download CSV";		
		$dom = str_get_html($html);
		$form = $dom->find('form', 1);
		
		$e = $form->find('input[type=hidden]', 0);
		$post_data[$e->name] = $e->value;
		$button = $form->find('input[type=image]', 0);
		$post_data[$button->name] = $button->value;
		
		$dom->clear(); 
		unset($dom);

		$csv = doCurlPost($form->action, $post_data);				
	} else {
		// Credit Card (GET)
		echo $ec ? " - is EC" :  " - is CC";
		$href = $row->find('td', 3)->find('a', 0)->href;
		$html = doCurlGet($url . $href);
		$dom = str_get_html($html);
		
		$href = $dom->find('a', 0)->href;
		if ($href) {
			$html = doCurlGet($href);
			$dom = str_get_html($html);
		}
				
		// download CSV
		echo " - download CSV";
		$href = $dom->find('a[href*=event=csvExport]', 0)->href;
		$csv = doCurlGet($url . $href);
	}
	
	$row->clear(); 
	unset($row);
	
	echo "\n";
	$accounts[$nr] = ['desc' => $desc, 'csv' => $csv, 'nr' => $nr, 'type' => $ec?'ec':'cc'];
}

# print_r($accounts);

//
// Logout
//
echo "Logout!\n";
$href = '/dkb/-?$part=DkbTransactionBanking.infobar.logout-button&$event=logout';
$html = doCurlGet($url . $href);
$href = '/dkb/-?$javascript=disabled&$part=Welcome.logout';
$html = doCurlGet($url . $href);

//
// Parse CSV
//
echo "Parse CSV\n";
$transactions = array();
foreach ($accounts as $account) 
{
	$transactions = array_merge($transactions, processAccount($account));
}
push($transactions);
print_r($accounts);

?>


