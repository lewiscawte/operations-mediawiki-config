<?php

# MW_SECURE_HOST set from secure gateway?
$secure = getenv( 'MW_SECURE_HOST' );
$host = $secure ? $secure : $_SERVER['HTTP_HOST'];

require_once( '/usr/local/apache/common-local/multiversion/MWVersion.php' );
