<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'MSTeamsNotifications' );
	wfWarn(
		'Deprecated PHP entry point used for MSTeamsNotifications extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the MSTeamsNotifications extension requires MediaWiki 1.25+' );
}
