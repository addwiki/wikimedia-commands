<?php

namespace Addwiki\Commands\Wikimedia;

use ArrayAccess;
use GuzzleHttp\Client;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FeaturedArticleSize extends Command{

	public function __construct( ArrayAccess $appConfig ) {
		parent::__construct( null );
	}

	protected function configure() {
		$this
			->setName( 'wm:wp:featuredsize' )
			->setDescription( 'Reports the size of all featured articles on Wikipedias' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$client = new Client( array(
			'cookies' => true,
			'headers' => array( 'User-Agent' => 'addwiki-featuredarticlesize' ),
			'verify' => false, // eww
		) );

		// http://tinyurl.com/gtwzusq
		$queryLocation = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=PREFIX%20wd%3A%20%3Chttp%3A%2F%2Fwww.wikidata.org%2Fentity%2F%3E%0APREFIX%20wdt%3A%20%3Chttp%3A%2F%2Fwww.wikidata.org%2Fprop%2Fdirect%2F%3E%0APREFIX%20wikibase%3A%20%3Chttp%3A%2F%2Fwikiba.se%2Fontology%23%3E%0APREFIX%20p%3A%20%3Chttp%3A%2F%2Fwww.wikidata.org%2Fprop%2F%3E%0APREFIX%20ps%3A%20%3Chttp%3A%2F%2Fwww.wikidata.org%2Fprop%2Fstatement%2F%3E%0APREFIX%20pq%3A%20%3Chttp%3A%2F%2Fwww.wikidata.org%2Fprop%2Fqualifier%2F%3E%0APREFIX%20rdfs%3A%20%3Chttp%3A%2F%2Fwww.w3.org%2F2000%2F01%2Frdf-schema%23%3E%0APREFIX%20bd%3A%20%3Chttp%3A%2F%2Fwww.bigdata.com%2Frdf%23%3E%0A%0A%23Cats%0ASELECT%20%3Fsitelink%0AWHERE%0A%7B%0A%09%3Fsitelink%20schema%3Aabout%20%3Fitem.%20%0A%20%20%20%20%3Fsitelink%20wikibase%3Abadge%20wd%3AQ17506997%20%0A%7D&format=json';
		$queryResult = $client->get( $queryLocation );
		$queryResult = $queryResult->getBody();
		$queryResult = json_decode( $queryResult, true );
		$links = array();

		foreach( $queryResult['results']['bindings'] as $result ) {
			$links[] = $result['sitelink']['value'];
		}

		$groupedLinks = array();
		$articles = 0;
		foreach( $links as $key => $link ) {
			$parsedLink = parse_url( $link );
			if( !strstr( $parsedLink['host'], '.wikipedia.org' ) ) {
				continue;
			}
			$article = str_replace( '/wiki/', '', $parsedLink['path'] );
			$article = urldecode( $article );
			$groupedLinks[$parsedLink['host']][$article] = array(
				'page' => 0,
				'images' => 0,
			);
			$articles++;
		}

		echo "\n";
		echo count( $groupedLinks ) . ' site to check' . "\n";
		echo $articles . ' articles to check' . "\n";
		echo "\n";

		$totalPageSize = 0;
		$totalImageSize = 0;

		foreach( $groupedLinks as $siteDomain => $articleData ) {
			echo 'Total size so far: ' . ( $totalImageSize + $totalPageSize ) . "\n";
			echo 'Images: ' . $totalImageSize . "\n";
			echo 'Text: ' . $totalPageSize . "\n";

			echo "\n\n" . $siteDomain . "\n";
			$mw = new MediawikiApi( 'https://' . $siteDomain . '/w/api.php', $client );
			foreach ( array_chunk( $articleData, 25, true ) as $chunkArticleData ) {
				$chunkedArticles = implode( '|', array_keys( $chunkArticleData ) );
				$response = $mw->getRequest(
					new SimpleRequest(
						'query', [
							'prop' => 'revisions',
							'titles' => $chunkedArticles,
							'rvprop' => 'size',
						]
					)
				);
				echo ".";
				foreach( $response['query']['pages'] as $pageInfo ) {
					$size = $pageInfo['revisions'][0]['size'];
					$totalPageSize += $size;
					echo "a";
				}
//				foreach( array_keys( $chunkArticleData ) as $article ) {
//					$response = $mw->getRequest(
//						new SimpleRequest(
//							'query', [
//								'generator' => 'images',
//								'titles' => $article,
//								'prop' => 'imageinfo',
//								'iiprop' => 'size',
//								'iilimit' => 500,
//							]
//						)
//					);
//					echo ".";
//					foreach ( $response['query']['pages'] as $imageInfo ) {
//						$size = $imageInfo['imageinfo'][0]['size'];
//						$totalImageSize += $size;
//						echo "i";
//					}
//				}
			}
		}

		echo "\n\n";
		echo 'Total size: ' . ( $totalImageSize + $totalPageSize ) . "\n";
		echo 'Images: ' . $totalImageSize . "\n";
		echo 'Text: ' . $totalPageSize . "\n";
		echo "\nDone!\n";
	}

}