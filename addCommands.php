<?php

$GLOBALS['awwCommands'][] = function ( $awwConfig ) {
	return array(
		new \Addwiki\Commands\Wikimedia\ExtensionToWikidata( $awwConfig ),
		new \Addwiki\Commands\Wikimedia\WikidataReferencer\WikidataReferencerCommand( $awwConfig ),
	);
};