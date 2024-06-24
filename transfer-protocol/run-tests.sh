#!/bin/bash
#COMMAND="phpunit tests/WP_Migration_*"
#COMMAND="phpunit tests/*.php"
#COMMAND="phpunit tests/WP_Block_Markup_Url_Processor_Tests.php"
#COMMAND="phpunit -c phpunit.xml"
#$COMMAND
#fswatch -o ./**/*.php | xargs -n1 -I{} $COMMAND

for i in $(ls tests/*.php | grep -v URL_Parser); do
    phpunit $i
done
