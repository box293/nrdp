#!/bin/bash -e
#
# Copyright (c) 2010-2011 Nagios Enterprises, LLC.
# 
#
###########################

# Setup Defaults
CONFIG=./nrds.cfg
COMMAND_PREFIX=""

PROGNAME=$(basename $0)
RELEASE="Revision 0.1"

# Functions plugin usage
print_release() {
    echo "$RELEASE"
}
print_usage() {
	echo ""
	echo "$PROGNAME $RELEASE - Sends passive checks to Nagios NRPD server"
	echo ""
	echo "Usage: nrds.sh -H hostname [-c /path/to/nrds.cfg]"
	echo ""
    echo "Usage: $PROGNAME -h"
    echo ""
}

print_help() {
		print_usage
        echo ""
        echo "This script is used to send passive checks in nrds.cfg"
		echo "to Nagios NRPD server"
		echo ""
		exit 0
}

while getopts "c:H:hv" option
do
  case $option in
    c) CONFIG=$OPTARG ;;
	H) hostname=$OPTARG ;;
	h) print_help ;;
	v) print_release
		exit 0 ;;
  esac
done
if [ ! $hostname ]; then
 print_usage
 exit 1
fi
if [ ! -f $CONFIG ];then
	echo "Could not find config file at "$CONFIG
	exit 1
fi

# Process the config
valid_fields=(URL TOKEN TMPDIR SEND_NRDP COMMAND_PREFIX CONFIG_NAME CONFIG_VERSION CONFIG_NAME UPDATE_CONFIG UPDATE_PLUGINS)
i=0
while read line; do
if [[ "$line" =~ ^[^\#\;]*= ]]; then
	#grab all the commands
	if [ ${line:0:7} == "command" ];then
		name[i]=`echo $line | cut -d'=' -f 1`
		service[i]=${name[i]:8:(${#name[i]}-8-1)}
		value[i]=`echo $line | cut -d'=' -f 2-`
		((i++))
	else
	# not a command lets process the rest of the config
	# first make sure it is part of valid_fields
		if [[ "${valid_fields[@]}" =~ `echo $line | cut -d'=' -f 1` ]];then
			eval $line
		else	
			echo "ERROR: `echo $line | cut -d'=' -f 1` is not a valid cfg field."
			exit 1
		fi
	fi
fi
done < $CONFIG
if [ ! -f $SEND_NRDP ];then
	echo "Could not find SEND_NRDP file at $SEND_NRDP"
	exit 1
fi

for (( i=0; i<=$(( ${#service[*]} -1 )); i++ ))
do
		set +e
		output=$(eval "$COMMAND_PREFIX ${value[$i]}")
		status="$?"
		set -e
	if [ "${service[$i]}" == "__HOST__" ];then
		senddata="$senddata$hostname\t$status\t$output\n"
	else
		senddata="$senddata$hostname\t${service[$i]}\t$status\t$output\n"
	fi
done
#echo -e $senddata
echo -e $senddata | $SEND_NRDP -u $URL -t $TOKEN