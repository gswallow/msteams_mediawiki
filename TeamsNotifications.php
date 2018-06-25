<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'TeamsNotifications' );
	wfWarn(
		'Deprecated PHP entry point used for TeamsNotifications extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the TeamsNotifications extension requires MediaWiki 1.25+' );
}
