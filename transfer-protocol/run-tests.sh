#!/bin/bash
COMMAND="phpunit tests/WP_URL_In_Text_Processor*"
$COMMAND
fswatch -o ./**/*.php | xargs -n1 -I{} $COMMAND
