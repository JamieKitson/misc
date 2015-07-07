#!/bin/bash

DATE=$(date +%s)

TEMP=$(sensors | egrep -E "(temp[1-3]|fan1)" | sort | grep -v N | awk '{print $2}' | tr -s '°C\n+' ' ')

CPU=$(mpstat 1 1 | grep Average | awk '{print 100 - $12}')
#$(top -bn2 | grep Cpu | tail -n1 | awk '{print 100 - $8}')

R1=`cat /sys/class/net/eth0/statistics/rx_bytes`
T1=`cat /sys/class/net/eth0/statistics/tx_bytes`
sleep 10
R2=`cat /sys/class/net/eth0/statistics/rx_bytes`
T2=`cat /sys/class/net/eth0/statistics/tx_bytes`
TBPS=`expr $T2 - $T1`
RBPS=`expr $R2 - $R1`
TKBPS=`expr $TBPS / 10`
#24`
RKBPS=`expr $RBPS / 10`
#24`
#echo "tx eth0: $TKBPS kb/s rx eth0: $RKBPS kb/s"

echo $DATE $TEMP $CPU $TKBPS $RKBPS
