<?php

namespace Addwiki\Wikimedia\Commands\WikidataReferencer;

use Addwiki\Mediawiki\Api\Client\Action\ActionApi;
use Addwiki\Mediawiki\Api\Guzzle\ClientFactory;
use Addwiki\Mediawiki\Api\MediawikiFactory;
use GuzzleHttp\Client;
use InvalidArgumentException;

class WikimediaMediawikiFactoryFactory {

	private Client $client;

	public function __construct( ClientFactory $clientFactory ) {
		$this->client = $clientFactory->getClient();
	}

	/**
	 * @todo this could be in a lib? Also this needs more sites adding to it!
	 *
	 *
	 */
	public function getFactory( string $siteID ): MediawikiFactory {
		$lastFour = substr( $siteID, -4 );
		if ( $lastFour == 'wiki' ) {
			$firstPart = substr( $siteID, 0, -4 );
			if ( strlen( $firstPart ) >= 2 ) {
				$firstPart = str_replace( '_', '-', $firstPart );
				return new MediawikiFactory(
					new ActionApi(
						sprintf( 'https://%s.wikipedia.org/w/api.php', $firstPart ),
						null,
						$this->client
					)
				);
			}
		}

		throw new InvalidArgumentException( __CLASS__ . ' cannot create factories for given wikicode' );
	}

}
