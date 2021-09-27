#!/usr/bin/env bash

yakpro-po GumPress.php --no-obfuscate-class-name --no-obfuscate-namespace-name --no-obfuscate-method-name --no-obfuscate-constant-name --no-obfuscate-function-name --no-shuffle-statements -o obfuscated.php

cp obfuscated.php /Users/avram/code/wordpress/wp-content/plugins/wooplatnica/trunk/GumPress.php

