<?php
defined('_SECURE_') or die('Forbidden');

/*
 * Implementations of hook checkavailablekeyword()
 *
 * @param $keyword
 *   checkavailablekeyword() will insert keyword for checking to the hook here
 * @return
 *   TRUE if keyword is available
 */
function sms_subscribe_hook_checkavailablekeyword($keyword) {
	$ok = true;
	$db_query = "SELECT subscribe_id FROM " . _DB_PREF_ . "_featureSubscribe WHERE subscribe_keyword='$keyword'";
	if ($db_result = dba_num_rows($db_query)) {
		$ok = false;
	}
	return $ok;
}

/*
 * Implementations of hook setsmsincomingaction()
 *
 * @param $sms_datetime
 *   date and time when incoming sms inserted to playsms
 * @param $sms_sender
 *   sender on incoming sms
 * @param $subscribe_keyword
 *   check if keyword is for sms_subscribe
 * @param $subscribe_param
 *   get parameters from incoming sms
 * @param $sms_receiver
 *   receiver number that is receiving incoming sms
 * @return $ret
 *   array of keyword owner uid and status, TRUE if incoming sms handled
 */
function sms_subscribe_hook_setsmsincomingaction($sms_datetime, $sms_sender, $subscribe_keyword, $subscribe_param = '', $sms_receiver = '', $raw_message = '') {
	$ok = false;
	$db_query = "SELECT * FROM "._DB_PREF_."_featureSubscribe WHERE subscribe_keyword='$subscribe_keyword'";
	$db_result = dba_query($db_query);
	if ($db_row = dba_fetch_array($db_result)) {
		if ($db_row['uid'] && $db_row['subscribe_enable']) {
			logger_print('begin k:'.$subscribe_keyword.' c:'.$subscribe_param, 2, 'sms_subscribe');
			if (sms_subscribe_handle($db_row,$sms_datetime,$sms_sender,$subscribe_keyword,$subscribe_param,$sms_receiver,$raw_message)) {
				$ok = true;
			}
			$status = ( $ok ? 'handled' : 'unhandled' );
			logger_print('end k:'.$subscribe_keyword.' c:'.$subscribe_param.' s:'.$status, 2, 'sms_subscribe');
		}
	}
	$ret['uid'] = $c_uid;
	$ret['status'] = $ok;
	return $ret;
}

function sms_subscribe_handle($list, $sms_datetime, $sms_sender, $subscribe_keyword, $subscribe_param = '', $sms_receiver = '', $raw_message = '') {
	global $core_config;
	$ok = false;
	$c_uid = $list['uid'];
	$subscribe_keyword = strtoupper($subscribe_keyword);
	$username = uid2username($c_uid);
	logger_print("username:".$username." sender:".$sms_sender." keyword:".$subscribe_keyword." param:".$subscribe_param, 3, "sms_subscribe");
	$subscribe_accept_param = $list['subscribe_param'];
	$subscribe_reject_param = $list['unsubscribe_param'];
	$forward_param = $list['forward_param'];
	// for later use
	$subscribe_param_array = explode(" ", $subscribe_param);
	$forward_sms = '';
	for ($i=1; $i<sizeof($subscribe_param_array); $i++) {
		$forward_sms .= $subscribe_param_array[$i] . ' ';
	}
	$forward_sms = substr($forward_sms,0,-1);
	// check for BC sub-keyword
	$subscribe_id = $list['subscribe_id'];
	$c_arr = explode(' ', $subscribe_param);

	// check for BC/forward param
	$bc = trim(strtoupper($c_arr[0]));
	if (($bc=='BC') || ($forward_param && ($bc==$forward_param))) {
		for ($i=1;$i<count($c_arr);$i++) {
			$msg0 .= $c_arr[$i].' ';
		}
		$message = trim($msg0);
		$bc_to = '';
		$db_query1 = "SELECT member_number FROM " . _DB_PREF_ . "_featureSubscribe_member WHERE subscribe_id = '$subscribe_id'";
		$db_result1 = dba_query($db_query1);
		while ($db_row1 = dba_fetch_array($db_result1)) {
			$bc_to[] = $db_row1['member_number'];
		}
		if (is_array($bc_to) && count($bc_to)>0) {
			$unicode = core_detect_unicode($message);
			logger_print('BC sender:'.$sms_sender.' keyword:'.$subscribe_keyword.' count:'.count($bc_to).' m:'.$message, 3, "sms_subscribe");
			list($ok, $to, $smslog_id, $queue) = sendsms($username, $bc_to, $message, 'text', $unicode);
			return true;
		} else {
		    return false;
		}
	}

	// check for subscribe/unsubscribe sub-keyword
	$subscribe_param = trim(strtoupper($subscribe_param));
	if ($sms_to = $sms_sender) {
		$msg1 = $list['subscribe_msg'];
		$msg2 = $list['unsubscribe_msg'];
		$db_query = "SELECT * FROM " . _DB_PREF_ . "_featureSubscribe_member WHERE member_number='$sms_to' AND subscribe_id='$subscribe_id'";
		$db_result = dba_query($db_query);
		$db_row = dba_fetch_array($db_result);
		$num_rows = ( dba_num_rows($db_query) ? 1 : 0 );
		if ($num_rows == 0) {
			$member = false;
			switch ($subscribe_param) {
				case "ON" :
				case "IN" :
				case "REG" :
				case $subscribe_accept_param :
					$message = $msg1;
					$db_query = "INSERT INTO " . _DB_PREF_ . "_featureSubscribe_member (subscribe_id,member_number,member_since) VALUES ('$subscribe_id','$sms_to','".$core_config['datetime']['now']."')";
					$logged = dba_query($db_query);
					logger_print('REG SUCCESS sender:'.$sms_sender.' keyword:'.$subscribe_keyword.' mobile:'.$sms_to, 2, "sms_subscribe");
					$ok = true;
					break;
				case "OFF" :
				case "OUT" :
				case "UNREG" :
				case $subscribe_reject_param :
					$message = _('You are not a member');
					logger_print('UNREG FAILED sender:'.$sms_sender.' keyword:'.$subscribe_keyword.' mobile:'.$sms_to, 2, "sms_subscribe");
					$ok = true;
					break;
				default :
					$message = _('Unknown SMS format');
					logger_print('REG unknown format sender:'.$sms_sender.' keyword:'.$subscribe_keyword.' mobile:'.$sms_to, 2, "sms_subscribe");
					$ok = true;
					break;
			}
		} else {
			$member = true;
			switch ($subscribe_param) {
				case "OFF" :
				case "OUT" :
				case "UNREG" :
				case $subscribe_reject_param :
					$message = $msg2;
					$success = 'fail to delete member';
					$db_query = "DELETE FROM " . _DB_PREF_ . "_featureSubscribe_member WHERE member_number='$sms_to' AND subscribe_id='$subscribe_id'";
					$deleted = dba_query($db_query);
					$success = 'FAILED';
					if ($deleted) {
						$success = 'SUCCESS';
						$ok = true;
					}
					logger_print('UNREG '.$success.' sender:'.$sms_sender.' keyword:'.$subscribe_keyword.' mobile:'.$sms_to, 2, "sms_subscribe");
					break;
				case "ON" :
				case "IN" :
				case "REG" :
				case $subscribe_accept_param :
					$message = _('You already a member');
					logger_print('REG fail already a member sender:'.$sms_sender.' keyword:'.$subscribe_keyword.' mobile:'.$sms_to, 2, "sms_subscribe");
					$ok = true;
					break;
				default :
					$message = _('Unknown sms format');
					logger_print('Unknown format sender:'.$sms_sender.' keyword:'.$subscribe_keyword.' mobile:'.$sms_to, 2, "sms_subscribe");
					$ok = true;
					break;
			}
		}
		list($ok,$to,$smslog_id,$queue) = sendsms($username, $sms_to, $message);
		$ok = $ok[0];
	} else {
		$ok = false;
	}
	return $ok;
}

/*
 * intercept incoming sms and look for keyword BC followed by subscribe keyword
 * this feature will do BC but for subscribe keyword
 *
 * sms format:
 *   BC <sms_subscribe_keyword> <message>
 *   #<sms_subscribe_keyword> <message>
 *
 * @param $sms_datetime
 *   incoming SMS date/time
 * @param $sms_sender
 *   incoming SMS sender
 * @message
 *   incoming SMS message before intercepted
 * @param $sms_receiver
 *   receiver number that is receiving incoming SMS
 * @return
 *   array $ret
 */
function sms_subscribe_hook_interceptincomingsms($sms_datetime, $sms_sender, $message, $sms_receiver) {
	$msg = explode(" ", $message);
	$bc = strtoupper($msg[0]);
	$keyword = strtoupper($msg[1]);
	$message = '';
	for ($i=2;$i<count($msg);$i++) {
		$message .= $msg[$i].' ';
	}
	$message = trim($message);
	$hooked = false;
	if (($bc=='BC') || ($bc=='#'.$keyword)) {
		// if not available then the keyword is exists
		if (! sms_subscribe_hook_checkavailablekeyword($keyword)) {
			$c_uid = mobile2uid($sms_sender);
			$c_username = uid2username($c_uid);
			if ($c_uid && $c_username) {
				$sms_datetime = core_display_datetime($sms_datetime);
				logger_print("dt:".$sms_datetime." s:".$sms_sender." r:".$sms_receiver." uid:".$c_uid." username:".$c_username." bc:".$bc." keyword:".$keyword." message:".$message, 3, "sms_subscribe");
				$hooked = true;
			}
		}
	}
	$ret = array();
	if ($hooked) {
		$ret['modified'] = true;
		$ret['hooked'] = true;
		$ret['param']['message'] = $keyword.' BC '.$message;
	}
	return $ret;
}

?>