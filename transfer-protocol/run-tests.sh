#!/bin/bash
COMMAND="phpunit tests/WP_Block_Markup_Url*"
$COMMAND
fswatch -o ./**/*.php | xargs -n1 -I{} $COMMAND
