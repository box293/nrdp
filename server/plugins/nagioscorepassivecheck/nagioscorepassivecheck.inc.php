<?php
//
// Nagios Core Passive Check NRDP Plugin
//
// Copyright (c) 2010-2017 - Nagios Enterprises, LLC. All rights reserved.
// License: Nagios Open Software License <http://www.nagios.com/legal/licenses>
//

require_once(dirname(__FILE__).'/../../config.inc.php');
require_once(dirname(__FILE__).'/../../includes/utils.inc.php');


register_callback(CALLBACK_PROCESS_REQUEST, 'nagioscorepassivecheck_process_request');


function nagioscorepassivecheck_process_request($cbtype, $args)
{
    $cmd = grab_array_var($args, "cmd");
    _debug("nagioscorepassivecheck_process_request(cbtype = {$cbtype}, args[cmd] = {$cmd}");

    switch ($cmd) {

        // Check data
        case "submitcheck":
            nagioscorepassivecheck_submit_check_data();
            break;

        // Something else we don't handle...
        default:
            break;
    }

    _debug("nagioscorepassivecheck_process_request() had no registered callbacks, returning");
}


function nagioscorepassivecheck_submit_check_data()
{
    global $cfg;
    global $request;

    foreach($request as $index => $req) {
        if (is_array($req)) {
            $req = print_r($req, true);
        }
        _debug("REQUEST: [{$index}] {$req}");
    }

    // Check results can be passed as XML data or JSON data
    $xmldata = grab_request_var("XMLDATA");	
    $jsondata = grab_request_var("JSONDATA");
    
    // Make sure we have data
    if (!have_value($xmldata) & !have_value($jsondata)) {
        _debug("no xmldata or jsondata, bailing");
        handle_api_error(ERROR_NO_DATA);
    }

    // Convert to xml
    if (have_value($xmldata)) {

        _debug('have xml');
        $xml = @simplexml_load_string($xmldata);

        if (!$xml) {
            $xmlerr = print_r(libxml_get_errors(), true);
            _debug("conversion to xml failed: {$xmlerr}");
            echo $xmlerr;        
            handle_api_error(ERROR_BAD_XML);
        }

        $method = 'xml';
        _debug("our xml: " . print_r($xml, true));
    }

    else if (have_value($jsondata)) {

        _debug('have json');
        $json = @json_decode($jsondata, true);

        if (!$json) {
            if (version_compare(phpversion(), '5.5.0', '>=')) {
                $jsonerr = print_r(json_last_error_msg(), true);
            } else {
                $jsonerr = print_r(json_last_error(), true);
            }
            _debug("conversion to json failed: {$jsonerr}");
            handle_api_error(ERROR_BAD_JSON);
        }

        $method = 'json';
        _debug("our json: " . print_r($json, true));
    }


    // Make sure we can write to check results dir
    if (!isset($cfg["check_results_dir"])) {
        _debug('we have no cfg[check_results_dir], bailing');
        handle_api_error(ERROR_NO_CHECK_RESULTS_DIR);
    }
    if (!file_exists($cfg["check_results_dir"])) {
        _debug("cfg[check_results_dir] ({$cfg['check_results_dir']}) doesn't exist, bailing");
        handle_api_error(ERROR_BAD_CHECK_RESULTS_DIR);
    }

    $total_checks = 0;

    // Process each result
    if ($method == "xml") {
		foreach ($xml->checkresult as $cr) {

			// Get check result type
			$type = "host";
			foreach ($cr->attributes() as $var => $val) {
				if ($var == "type") {
					$type = strval($val);
				}
			}

			// Common elements
			$hostname = strval($cr->hostname);
			$state = intval($cr->state);
			$output = strval($cr->output);
			$output = str_replace("\n", "\\n", $output);

			// Service checks
			$servicename = "";
			if ($type == "service") {
				$servicename = strval($cr->servicename);
			}

			if (isset($cr->time) && isset($cfg["allow_old_results"])) {
				if ($cfg["allow_old_results"]) {
					$time = intval($cr->time);
					nrdp_write_check_output_to_ndo($hostname, $servicename, $state, $output, $type, $time);
				}
			} else {
				nrdp_write_check_output_to_cmd($hostname, $servicename, $state, $output, $type);
			}

			$total_checks++;
		}
    
        _debug("all nrdp (xml) checks have been written");
		output_api_header();
		
		echo "<result>\n";
		echo "  <status>0</status>\n";
		echo "  <message>OK</message>\n";
		echo "    <meta>\n";
		echo "       <output>".$total_checks." checks processed.</output>\n";
		echo "    </meta>\n";
		echo "</result>\n";

	}
	else if ($method == "json") {
		foreach ($json["checkresults"] as $cr) {
			
			// Get check result type
			$type = "host";
			foreach ($cr["checkresult"] as $var => $val) {
				if ($var == "type") {
					$type = strval($val);
				}
			}
			
			// Common elements
			$hostname = strval($cr["hostname"]);
			$state = intval($cr["state"]);
			$output = strval($cr["output"]);
			$output = str_replace("\n", "\\n", $output);

			// Service checks
			$servicename = "";
			if ($type == "service") {
				$servicename = strval($cr["servicename"]);
			}

			if (isset($cr["time"]) && isset($cfg["allow_old_results"])) {
				if ($cfg["allow_old_results"]) {
					$time = intval($cr["time"]);
					nrdp_write_check_output_to_ndo($hostname, $servicename, $state, $output, $type, $time);
				}
			} else {
				nrdp_write_check_output_to_cmd($hostname, $servicename, $state, $output, $type);
			}

			$total_checks++;
		
		}
	
        _debug("all nrdp (json) checks have been written");
		output_api_header();

		if (isset($request['pretty'])) {
			echo "{\n";
			echo "  \"result\" : {\n";
			echo "    \"status\" : \"0\",\n";
			echo "    \"message\" : \"OK\",\n";
			echo "    \"output\" : \"".$total_checks." checks processed.\"\n";
			echo "  }\n";
			echo "}\n";
		} else {
			echo "{ \"result\" : {  \"status\" : \"0\", \"message\" : \"OK\", \"output\" : \"".$total_checks." checks processed.\" } }\n";
		}
	}

    exit();
}


// Write out the check result to Nagios Core
function nrdp_write_check_output_to_cmd($hostname, $servicename, $state, $output, $type)
{
    _debug("nrdp_write_check_output_to_cmd(hostname={$hostname}, servicename={$servicename}, state={$state}, type={$type}, output={$output}");
    global $cfg;

    ////// WRITE THE CHECK RESULT //////

    // Create a temp file to write to
    $tmpname = tempnam($cfg["check_results_dir"], "c");

    // Check if the file is in the check_results_dir (or its symlink)
    if (strpos($tmpname, realpath($cfg["check_results_dir"])) === false) {

        unlink($tmpname);
        _debug("tmpname({$tmpname}) not in cfg[check_results_dir] ({$cfg['check_results_dir']}), (or a symlink) bailing");
        handle_api_error(ERROR_BAD_CHECK_RESULTS_DIR);
    }

    $fh = fopen($tmpname, "w");

    fprintf($fh, "### NRDP Check ###\n");
    fprintf($fh, "start_time=%d.0\n", time());
    fprintf($fh, "# Time: %s\n", date('r'));
    fprintf($fh, "host_name=%s\n", $hostname);
    if ($type == "service") {
        fprintf($fh, "service_description=%s\n", $servicename);
    }
    fprintf($fh, "check_type=1\n"); // 0 for active, 1 for passive
    fprintf($fh, "early_timeout=1\n");
    fprintf($fh, "exited_ok=1\n");
    fprintf($fh, "return_code=%d\n", $state);
    fprintf($fh, "output=%s\\n\n", $output);
    
    // Close the file
    fclose($fh);
    
    // Change ownership and perms
    $command_group = grab_array_var($cfg, "nagios_command_group", "nagcmd");
    // chgrp if the function we want doesn't exist
    // or if it does exist and doesn't return false
    if (!function_exists("posix_getgrnam") 
        || posix_getgrnam($command_group) !== false) {
        chgrp($tmpname, $command_group);
    } else {
        _debug("nagios_command_group={$command_group} does not exist, not chgrp()ing");
    }
    chmod($tmpname, 0770);
    
    // Create an ok-to-go, so Nagios Core picks it up
    $fh = fopen($tmpname.".ok", "w+");
    fclose($fh);
    _debug("nrdp_write_check_output_to_cmd() successful");
}


// Writes the check output into the NDO database skipping Nagios Core
// so that we can input old (past checks) data into the database
function nrdp_write_check_output_to_ndo($hostname, $servicename, $state, $output, $type, $time)
{
    _debug("nrdp_write_check_output_to_ndo(hostname={$hostname}, servicename={$servicename}, state={$state}, type={$type}, time={$time}, output={$output}");

    // Connect to the NDOutils database with Nagios XI config options
    require("/usr/local/nagiosxi/html/config.inc.php");
    $ndodb = $cfg['db_info']['ndoutils'];

    // Get the nagios install configuration
    $nagios_cfg = read_nagios_config_file();

    $db = new MySQLi($ndodb['dbserver'], $ndodb['user'], $ndodb['pwd'], $ndodb['db']);
    if ($db->connect_errno) {
        _debug("Coudln't connect to database, bailing");
        return false;
    }

    $state = intval($state);
    $log_state_change = false;

    if (strpos("\n", $output) !== false) {
        list($output, $long_output) = explode("\n", $output, 2);
    } else {
        $long_output = '';
    }

    // Drop off the perfdata if it exists, we won't need it
    $x = explode("|", $output);
    $output = $x[0];
    $x = explode("|", $long_output);
    $long_output = $x[0];

    // Check for 255 state (out of bounds error)
    if ($state > 3) {
        // Do out of bounds error and break
        return;
    }

    // Try to get the 'Object ID'
    $sql = sprintf("SELECT object_id FROM nagios_objects WHERE name1 = '%s' AND name2 = '%s';", $hostname, $servicename);
    $result = $db->query($sql);
    if ($result->num_rows > 0) {

        $r = $result->fetch_object();
        $object_id = intval($r->object_id);

        // We now have the object_id so let's start the processing
        if ($type == "service") {

            $update_status_sql = "";
            $add_last_hard_state_change = false;
            $last_hard_state = 0;
            $add_last_state_change = false;

            $sql = sprintf("SELECT * FROM nagios_servicestatus WHERE service_object_id = %d;", $object_id);
            $result = $db->query($sql);
            $status = $result->fetch_object();

            // Verify passive checks are enabled?
            if (!$status->passive_checks_enabled) {
                //return;
            }

            $state_type = $status->state_type;
            $current_attempt = $status->current_check_attempt + 1;
            if ($current_attempt >= $status->max_check_attempts) {
                $current_attempt = $status->max_check_attempts;
                $state_type = 1;
                $add_last_hard_state_change = true;
                $log_state_change = true;
            } else {
                $state_type = 0;
            }

            // Check to see if the state type has changed (from SOFT/HARD)
            if ($state != $status->current_state) {
                if ($status->current_state == 0) {
                    $current_attempt = 1;
                }
                $log_state_change = true;
                $add_last_state_change = true;
            }

            // If state is 0 - force a hard check
            if ($state == 0) {
                $state_type = 1;
                $current_attempt = 1;
                if ($log_state_change) {
                    $add_last_hard_state_change = true;
                    $last_hard_state = 1;
                }
            }

            $update_status_sql .= sprintf("current_check_attempt = %d, ", $current_attempt);

            if ($add_last_hard_state_change) {
                $last_hard_state = $status->current_state;
                $update_status_sql .= sprintf("last_hard_state_change = FROM_UNIXTIME(%d), ", $time);
            }

            switch ($state) {
                case 0:
                    $update_status_sql .= sprintf("last_time_ok = FROM_UNIXTIME(%d), ", $time);
                    break;
                case 1:
                    $update_status_sql .= sprintf("last_time_warning = FROM_UNIXTIME(%d), ", $time);
                    break;
                case 2:
                    $update_status_sql .= sprintf("last_time_critical = FROM_UNIXTIME(%d), ", $time);
                    break;
                default:
                    $update_status_sql .= sprintf("last_time_unknown = FROM_UNIXTIME(%d), ", $time);
                    break;
            }

            if ($add_last_state_change) {
                $update_status_sql .= sprintf("last_state_change = FROM_UNIXTIME(%d), ", $time);
            }

            // Update the service status
            $sql = sprintf("UPDATE nagios_servicestatus
                            SET status_update_time = FROM_UNIXTIME(%d),
                            has_been_checked = 1,
                            output = '%s',
                            long_output = '%s',
                            current_state = %d,
                            state_type = %d,
                            last_check = FROM_UNIXTIME(%d),
                            check_type = 1,
                            execution_time = 0,
                            %s
                            latency = 0
                            WHERE service_object_id = %d;",
                            $time, $db->real_escape_string($output), $db->real_escape_string($long_output), $state, $state_type, $time, $update_status_sql, $object_id);
            $db->query($sql);

            // Update the state history
            if ($log_state_change) {
                $sql = sprintf("INSERT INTO nagios_statehistory
                                (instance_id, state_time, object_id, state_change, state, state_type, current_check_attempt, max_check_attempts, last_state, last_hard_state, output, long_output)
                                VALUES (1, FROM_UNIXTIME(%d), %d, 1, %d, %d, %d, %d, %d, %d, '%s', '%s');",
                                $time, $object_id, $state, $state_type, $current_attempt, $status->max_check_attempts, $status->current_state, $last_hard_state,
                                $db->real_escape_string($output), $db->real_escape_string($long_output));
                $db->query($sql);
            }

            $logentry = "SERVICE ALERT: $hostname;$servicename;".human_readable_service_state($state).";".human_readable_state_type($state_type).";$state;$output";

            // Get the log id type based on the current state
            $service_ok_type = 8192;
            $service_unknown_type = 16384;
            $service_warning_type = 32768;
            $service_critical_type = 65536;
            if ($state == 0) {
                $logentry_type = $service_ok_type;
            } else if ($state == 1) {
                $logentry_type = $service_warning_type;
            } else if ($state == 2) {
                $logentry_type = $service_critical_type;
            } else {
                $logentry_type = $service_unknown_type;
            }
        
        } else if ($type == "host") {

            $update_status_sql = "";
            $log_state_change = false;
            $last_hard_state = 0;
            $add_last_state_change = false;

            $sql = sprintf("SELECT * FROM nagios_hoststatus WHERE host_object_id = %d;", $object_id);
            $result = $db->query($sql);
            $status = $result->fetch_object();

            // Verify passive checks are enabled?
            if (!$status->passive_checks_enabled) {
                //return;
            }

            if ($nagios_cfg['passive_host_checks_are_soft']) {

                // Passive host checks are SOFT - so let's calculate if the host is in soft or hard states
                $state_type = $status->state_type;
                $current_attempt = $status->current_check_attempt + 1;
                if ($current_attempt >= $status->max_check_attempts) {
                    $current_attempt = $status->max_check_attempts;
                    $state_type = 1;
                    $add_last_hard_state_change = true;
                    $log_state_change = true;
                } else {
                    $state_type = 0;
                }

                // Check to see if the state type has changed (from SOFT/HARD)
                if ($state != $status->current_state) {
                    if ($status->current_state == 0) {
                        $current_attempt = 1;
                    }
                    $log_state_change = true;
                    $add_last_state_change = true;
                }

                // If state is 0 - force a hard check
                if ($state == 0) {
                    $state_type = 1;
                    $current_attempt = 1;
                    if ($log_state_change) {
                        $add_last_hard_state_change = true;
                    }
                }

            } else {

                // Passive host checks are HARD - we will set them all to hard no matter what
                $state_type = 1;
                $current_attempt = 1;

                if ($state != $status->current_state) {
                    $log_state_change = true;
                    $add_last_hard_state_change = true;
                    $add_last_state_change = true;
                }

            }

            $update_status_sql .= sprintf("current_check_attempt = %d, ", $current_attempt);

            if ($add_last_hard_state_change) {
                $last_hard_state = $status->current_state;
                $update_status_sql .= sprintf("last_hard_state_change = FROM_UNIXTIME(%d), ", $time);
            }

            switch ($state) {
                case 0:
                    $update_status_sql .= sprintf("last_time_up = FROM_UNIXTIME(%d), ", $time);
                    break;
                case 1:
                    $update_status_sql .= sprintf("last_time_down = FROM_UNIXTIME(%d), ", $time);
                    break;
                default:
                    $update_status_sql .= sprintf("last_time_unreachable = FROM_UNIXTIME(%d), ", $time);
                    break;
            }

            if ($add_last_state_change) {
                $update_status_sql .= sprintf("last_state_change = FROM_UNIXTIME(%d), ", $time);
            }

            // Update the host status
            $sql = sprintf("UPDATE nagios_hoststatus
                            SET status_update_time = FROM_UNIXTIME(%d),
                            has_been_checked = 1,
                            output = '%s',
                            long_output = '%s',
                            current_state = %d,
                            state_type = %d,
                            last_check = FROM_UNIXTIME(%d),
                            check_type = 1,
                            execution_time = 0,
                            %s
                            latency = 0
                            WHERE host_object_id = %d;",
                            $time, $db->real_escape_string($output), $db->real_escape_string($long_output), $state, $state_type, $time, $update_status_sql, $object_id);
            $db->query($sql);

            // Update the state history
            if ($log_state_change) {
                $sql = sprintf("INSERT INTO nagios_statehistory
                                (instance_id, state_time, object_id, state_change, state, state_type, current_check_attempt, max_check_attempts, last_state, last_hard_state, output, long_output)
                                VALUES (1, FROM_UNIXTIME(%d), %d, 1, %d, %d, %d, %d, %d, %d, '%s', '%s');",
                                $time, $object_id, $state, $state_type, $current_attempt, $status->max_check_attempts, $status->current_state, $last_hard_state,
                                $db->real_escape_string($output), $db->real_escape_string($long_output));
                $db->query($sql);
            }

            $logentry = "HOST ALERT: $hostname;".human_readable_host_state($state).";".human_readable_state_type($state_type).";$output";
    
            // Get the log id based on the current state
            $host_ok_type = 1024;
            $host_down_type = 2048;
            $host_unreachable_type = 4096;
            if ($state == 0) {
                $logentry_type = $host_ok_type;
            } else if ($state == 1) {
                $logentry_type = $host_down_type;
            } else {
                $logentry_type = $host_unreachable_type;
            }

        }

        // Add a row into the log entries table
        $sql = sprintf("INSERT INTO nagios_logentries
                        (instance_id, logentry_time, entry_time, entry_time_usec, logentry_type, logentry_data, realtime_data, inferred_data_extracted)
                        VALUES (1, FROM_UNIXTIME(%d), FROM_UNIXTIME(%d), 0, %d, '%s', 1, 1);", $time, $time, $logentry_type, $db->real_escape_string($logentry));
        $db->query($sql);

        // Add log entry to spool so we can add it into the real log in the proper place
        $spool_dir = $cfg['root_dir'].'/tmp/passive_spool';

        if (!file_exists($spool_dir)) {
            mkdir($spool_dir);
            chmod($spool_dir, 0775);
        }

        // Add a new file with the log entries in it ...
        $latest_hour = date("M j, Y H:00", time());
        $spoolfile_time = strtotime($latest_hour);
        $logfile_entry = '['.$time.'] '.$logentry."\n";
        file_put_contents($spool_dir."/".$spoolfile_time.".spool", $logfile_entry, FILE_APPEND);
        chmod($spool_dir."/".$spoolfile_time.".spool", 0664);
        chgrp($spool_dir."/".$spoolfile_time.".spool", 'nagios');
    }

    $db->close();
    _debug("nrdp_write_check_output_to_ndo() successful");
    return;
}


function human_readable_state_type($state_type)
{
    switch ($state_type) {
        case 0:
            return "SOFT";
            break;
        case 1:
            return "HARD";
            break;
    }
}


function human_readable_host_state($state)
{
    switch ($state) {
        case 0:
            return "UP";
            break;
        case 1:
            return "DOWN";
            break;
        default:
            return "UNREACHABLE";
            break;
    }
}


function human_readable_service_state($state)
{
    switch ($state) {
        case 0:
            return "OK";
            break;
        case 1:
            return "WARNING";
            break;
        case 2:
            return "CRITICAL";
            break;
        default:
            return "UNKNOWN";
            break;
    }
}


function read_nagios_config_file()
{
    $nagios_cfg = file_get_contents("/usr/local/nagios/etc/nagios.cfg");
    $ncfg = explode("\n", $nagios_cfg);

    $nagios_cfg = array();
    foreach ($ncfg as $line) {
        if (strpos($line, "=") !== false) {
            $var = explode("=", $line);
            $nagios_cfg[$var[0]] = $var[1];
        }
    }

    return $nagios_cfg;
}
