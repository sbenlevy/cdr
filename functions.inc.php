<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
//This file is part of FreePBX.
//
//    FreePBX is free software: you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation, either version 2 of the License, or
//    (at your option) any later version.
//
//    FreePBX is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU General Public License for more details.
//
//    You should have received a copy of the GNU General Public License
//    along with FreePBX.  If not, see <http://www.gnu.org/licenses/>.
//
//    cdr module for FreePBX 2.7+
//    Copyright (C) 2010, 2011 Anthony Joseph Messina
//    Portions Copyright (C) 2011 Igor Okunev
//    Portions Copyright (C) 2011 Mikael Carlsson
//    Portions Copyright (C) 2006 Seth Sargent, Steven Ward

// NOTE: This function should probably be in a FreePBX library
// php function empty() treats 0 as empty, that is why I need the function below
// to be able to search for any number starting with 0
function is_blank($value) {
	return empty($value) && !is_numeric($value);
}


/* Asterisk RegExp parser */
function cdr_asteriskregexp2sqllike( $source_data, $user_num ) {
        $number = $user_num;
        if ( strlen($number) < 1 ) {
                $number = $_POST[$source_data];
        }
        if ( '__' == substr($number,0,2) ) {
                $number = substr($number,1);
        } elseif ( '_' == substr($number,0,1) ) {
                $number_chars = preg_split('//', substr($number,1), -1, PREG_SPLIT_NO_EMPTY);
                $number = '';
                foreach ($number_chars as $chr) {
                        if ( $chr == 'X' ) {
                                $number .= '[0-9]';
                        } elseif ( $chr == 'Z' ) {
                                $number .= '[1-9]';
                        } elseif ( $chr == 'N' ) {
                                $number .= '[2-9]';
                        } elseif ( $chr == '.' ) {
                                $number .= '.+';
                        } elseif ( $chr == '!' ) {
                                $_POST[ $source_data .'_neg' ] = 'true';
                        } else {
                                $number .= $chr;
                        }
                }
                $_POST[ $source_data .'_mod' ] = 'asterisk-regexp';
        }
        return $number;
}

function cdr_get_cel($uid, $cel_table = 'asteriskcdrdb.cel') {
	global $dbcdr;

	// common query components
	//
	$sql_base = "SELECT * FROM $cel_table WHERE "; 
	$sql_order = " ORDER BY eventtime, id";


	// get first set of CEL records
	//
	$sql_start = $sql_base . "uniqueid = '$uid' OR linkedid = '$uid'" . $sql_order;
	$pass = $dbcdr->getAll($sql_start,DB_FETCHMODE_ASSOC);
	if(DB::IsError($pass)) {
		die_freepbx($pass->getDebugInfo() . "SQL - <br /> $sql_start" );
	}

	$last_criteria = array();
	$next =array();
	$done = false;

	// continue querying all records based on the uniqueid and linkedid fields associated
	// with the first set we queried until we have found all of them. This usually results
	// in one or two more queries prior to the last one being identical indicating we have
	// found all the records
	//
	while (!$done) {
		unset($next);
		foreach ($pass as $set) {
			$next[] = $set['uniqueid'];
			$next[] = $set['linkedid'];
		}
		$next = array_unique($next);
		sort($next);

		// if our criteria is now the same then we have found everything
		//
		if ($next == $last_criteria) {
			$done = true;
			continue;
		}
		unset($pass);

		$set = "('" . implode($next,"','") . "')"; 
		$sql_next = $sql_base . "uniqueid IN $set OR linkedid IN $set" . $sql_order;
		$last_criteria = $next;
		$next = array();
		$pass = $dbcdr->getAll($sql_next,DB_FETCHMODE_ASSOC);
		if(DB::IsError($pass)) {
			die_freepbx($pass->getDebugInfo() . "SQL - <br /> $sql_next" );
		}
	}
	return $pass;
}

function cdr_download($data, $name) {
    $filesize = strlen($data);
    $mimetype = "application/octet-stream";
	
    // Make sure there's not anything else left
    cdr_ob_clean_all();
    // Start sending headers
    header("Pragma: public"); // required
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Cache-Control: private",false); // required for certain browsers
    header("Content-Transfer-Encoding: binary");
    header("Content-Type: " . $mimetype);
    header("Content-Length: " . $filesize);
    header("Content-Disposition: attachment; filename=\"" . $name . "\";" );
    // Send data
    echo $data;
    die();
}

function cdr_export_csv($csvdata) {
	// Searching for more than 10,000 records take more than 30 seconds.
	// php default timeout is 30 seconds, hard code it to 3000 seconds for now (which is WAY overkill).
	// TODO: make this value a setting in Advanced Settings
	set_time_limit(3000);
	$fname		= "cdr__" .  (string) time() . $_SERVER["SERVER_NAME"] . ".csv";
	$csv_header ="calldate,clid,src,dst,dcontext,channel,dstchannel,lastapp,lastdata,duration,billsec,disposition,amaflags,accountcode,uniqueid,userfield\n";
	$data 		= $csv_header;
	
	foreach ($csvdata as $csv) {
		$csv_line[0] 	= $csv['calldate'];
		$csv_line[1] 	= $csv['clid'];
		$csv_line[2] 	= $csv['src'];
		$csv_line[3] 	= $csv['dst'];
		$csv_line[4] 	= $csv['dcontext'];
		$csv_line[5]	= $csv['channel'];
		$csv_line[6] 	= $csv['dstchannel'];
		$csv_line[7] 	= $csv['lastapp'];
		$csv_line[8]	= $csv['lastdata'];
		$csv_line[9]	= $csv['duration'];
		$csv_line[10]	= $csv['billsec'];
		$csv_line[11]	= $csv['disposition'];
		$csv_line[12]	= $csv['amaflags'];
		$csv_line[13]	= $csv['accountcode'];
		$csv_line[14]	= $csv['uniqueid'];
		$csv_line[15]	= $csv['userfield'];

		for ($i = 0; $i < count($csv_line); $i++) {
			/* If the string contains a comma, enclose it in double-quotes. */
			if (strpos($csv_line[$i], ",") !== FALSE) {
				$csv_line[$i] = "\"" . $csv_line[$i] . "\"";
			}
			if ($i != count($csv_line) - 1) {
				$data .= $csv_line[$i] . ",";
			} else {
				$data .= $csv_line[$i];
			}
		}
		$data .= "\n";
		unset($csv_line);
	}
	cdr_download($data, $fname);
	return;
}

function cdr_ob_clean_all () {
    $ob_active = ob_get_length () !== false;
    while($ob_active) {
        ob_end_clean();
        $ob_active = ob_get_length () !== false;
    }
    return true;
}

?>
