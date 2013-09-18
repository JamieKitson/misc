#!/bin/bash

sensors | egrep -E "(temp[1-3]|fan1)" | awk '{print $2}' | tr -s '\n' ' ' | sed -e "s/^/\n$(date +%s) /" >> /home/jamie/misc/temp.log
