<?php
//
// NRDP Config File
//
// Copyright (c) 2010-2017 - Nagios Enterprises, LLC.
// License: Nagios Open Software License <http://www.nagios.com/legal/licenses>
//

// An array of one or more tokens that are valid for this NRDP install
// a client request must contain a valid token in order for the NRDP to response or honor the request
// NOTE: Tokens are just alphanumeric strings - make them hard to guess!
$cfg['authorized_tokens'] = array(
    //"mysecrettoken",  // <-- not a good token
    //"90dfs7jwn3",   // <-- a better token (don't use this exact one, make your own)
);
    
// Do we require that HTTPS be used to access NRDP?
// set this value to 'false' to disable HTTPS requirement
$cfg["require_https"] = false;

// Do we require that basic authentication be used to access NRDP?
// set this value to 'false' to disable basic auth requirement 
$cfg["require_basic_auth"] = false;

// What basic authentication users are allowed to access NRDP?
// comment this variable out to allow all authenticated users access to the NRDP
$cfg["valid_basic_auth_users"] = array(
    "nrdpuser"
);
    
// The name of the system group that has write permissions to the external command file
// this group is also used to set file permissions when writing bulk commands or passive check results
// NOTE: both the Apache and Nagios users must be a member of this group
$cfg["nagios_command_group"] = "nagcmd";

// Full path to Nagios external command file
$cfg["command_file"] = "/var/spool/nagios/cmd/nagios.cmd";

// Full path to check results spool directory
$cfg["check_results_dir"] = "/var/log/nagios/spool/checkresults";

// Should we allow external commands? Set to true or false (Boolean, not a string)
$cfg["disable_external_commands"] = false;


///////// DONT MODIFY ANYTHING BELOW THIS LINE /////////

$cfg['product_name'] = 'nrdp';
$cfg['product_version'] = '1.4.0'


?>
