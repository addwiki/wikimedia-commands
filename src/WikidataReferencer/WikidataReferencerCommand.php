<?php

namespace Addwiki\Commands\Wikimedia\WikidataReferencer;

use Addwiki\Commands\Wikimedia\SparqlQueryRunner;
use ArrayAccess;
use DataValues\Deserializers\DataValueDeserializer;
use DataValues\Serializers\DataValueSerializer;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool as RequestPool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\Guzzle\ClientFactory;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\DataModel\PageIdentifier;
use Mediawiki\DataModel\Title;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Stash\Driver\FileSystem;
use Stash\Pool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wikibase\Api\WikibaseFactory;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\ItemLookupException;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * @author Addshore
 */
class WikidataReferencerCommand extends Command {

	private $appConfig;

	/**
	 * @var SparqlQueryRunner
	 */
	private $sparqlQueryRunner;

	/**
	 * @var WikibaseFactory
	 */
	private $wikibaseFactory;

	/**
	 * @var MediawikiApi
	 */
	private $wikibaseApi;

	/**
	 * @var WikimediaMediawikiFactoryFactory
	 */
	private $wmFactoryFactory;

	/**
	 * @var MicrodataExtractor
	 */
	private $microDataExtractor;

	/**
	 * @var array 'type' => Referencer[]
	 */
	private $referencerMap = array();

	/**
	 * @var string[]
	 */
	private $instanceMap = array();

	/**
	 * @var Client
	 */
	private $externalLinkClient;

	private $oldProcessedListPath;

	/**
	 * @var Pool
	 */
	private $cache;

	public function __construct( ArrayAccess $appConfig ) {
		$this->appConfig = $appConfig;
		parent::__construct( null );
	}

	public function initServices( $tmpDir ) {
		$this->cache = new Pool( new FileSystem( array('path' => $tmpDir ) ) );
		$this->oldProcessedListPath = $tmpDir . DIRECTORY_SEPARATOR . 'addwiki-wikidatareferencer-alreadydone.txt';
		$clientFactory = new ClientFactory(
			array(
				'middleware' => array( EffectiveUrlMiddleware::middleware() ),
				'user-agent' => 'Addwiki - Wikidata Referencer',
			)
		);
		$guzzleClient = $clientFactory->getClient();

		$this->wmFactoryFactory = new WikimediaMediawikiFactoryFactory( $clientFactory );
		$this->microDataExtractor = new MicrodataExtractor();
		$this->sparqlQueryRunner = new SparqlQueryRunner( $guzzleClient );
		$this->externalLinkClient = $guzzleClient;

		$this->wikibaseApi = new MediawikiApi( 'https://www.wikidata.org/w/api.php', $guzzleClient );
		$this->wikibaseFactory = new WikibaseFactory(
			$this->wikibaseApi,
			new DataValueDeserializer(
				array(
					'boolean' => 'DataValues\BooleanValue',
					'number' => 'DataValues\NumberValue',
					'string' => 'DataValues\StringValue',
					'unknown' => 'DataValues\UnknownValue',
					'globecoordinate' => 'DataValues\Geo\Values\GlobeCoordinateValue',
					'monolingualtext' => 'DataValues\MonolingualTextValue',
					'multilingualtext' => 'DataValues\MultilingualTextValue',
					'quantity' => 'DataValues\QuantityValue',
					'time' => 'DataValues\TimeValue',
					'wikibase-entityid' => 'Wikibase\DataModel\Entity\EntityIdValue',
				)
			),
			new DataValueSerializer()
		);

		// Note: file can be updated using http://tinyurl.com/hdrurlu
		$filmGenreData = $this->sparqlQueryRunner->getItemIdStringsAndLabelsFromInstanceOf( 'Q201658' );
		$filmGenreRegexMap = array();
		foreach( $filmGenreData as $itemIdString => $label ) {
			if( preg_match( '/ films?/i', $label ) ) {
				$regex = '/^' .  preg_replace( '/ films?/i', '( film)?', $label ) . '$/i';
			} else {
				$regex = '/^' . $label . '( film)?' . '$/i';
			}
			$regex = preg_replace( '/science ?fiction/i', '(science ?fiction|sci-fi)', $regex );
			$filmGenreRegexMap[$itemIdString] = $regex;
		}

		$this->instanceMap = array(
			'Q5' => 'Person',
			'Q11424' => 'Movie',
		);
		$this->referencerMap = array(
			'Person' => array(
				new ThingReferencer(
					$this->wikibaseFactory,
					array(
						'P7' => 'sibling',//brother
						'P9' => 'sibling',//sister
						'P19' => 'birthPlace',
						'P20' => 'deathPlace',
						'P21' => 'gender',
						'P22' => 'parent',//father
						'P25' => 'parent',//mother
						'P26' => 'spouse',
						'P40' => 'children',
						'P27' => 'nationality',
						'P734' => 'familyName',
						'P735' => 'givenName',
					)
				),
				new DateReferencer(
					$this->wikibaseFactory,
					array(
						'P569' => 'birthDate',
						'P570' => 'deathDate',
					)
				)
			),
			'Movie' => array(
				new ThingReferencer(
					$this->wikibaseFactory,
					array(
						// Person
						'P57' => 'director',
						'P161' => 'actor',
						'P162' => 'producer',
						'P1040' => 'editor',
						'P58' => 'author',
						// Organization
						'P272' => array( 'creator', 'productionCompany' ),
						// Content
						'P364' => 'inLanguage',
						'P674' => 'character',
						'P840' => 'contentLocation',
						//Metadata
						'P166' => 'award',
						'P1657' => 'contentRating',
						'P2047' => 'duration',
						'P2360' => 'audience',
					)
				),
				new MultiTextReferencer(
					$this->wikibaseFactory,
					array(
						'P136' => 'genre',
					),
					array(
						'P136' => $filmGenreRegexMap,
					)
				),
				new DateReferencer(
					$this->wikibaseFactory,
					array(
						'P577' => 'datePublished',
					)
				)
			),
		);
	}

	protected function configure() {
		$defaultUser = $this->appConfig->offsetGet( 'defaults.user' );

		$this
			->setName( 'wm:wd:ref' )
			->setDescription( 'Adds references to Wikidata items' )
			->addOption(
				'user',
				null,
				( $defaultUser === null ? InputOption::VALUE_REQUIRED :
					InputOption::VALUE_OPTIONAL ),
				'The configured user to use',
				$defaultUser
			)
			->addOption(
				'sparql',
				null,
				InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
				'SPARQL query part'
			)
			->addOption(
				'item',
				null,
				InputOption::VALUE_OPTIONAL,
				'Item to target'
			)
			->addOption(
				'tmpDir',
				null,
				InputOption::VALUE_OPTIONAL,
				'Temporary directory to store a processed ID list in'
			);
	}

	/**
	 * @param string $link
	 *
	 * @return string
	 */
	private function normalizeExternalLink( $link ) {
		if ( strpos( $link, '//' ) === 0 ) {
			$link = 'http' . $link;
		}
		if( strpos( $link, '#' ) !== false ) {
			$link = strstr( $link, '#', true );
		}
		$link = trim( $link, '/' );

		// Normalize some domain specific stuff
		if( strstr( $link, '.imdb.' ) ) {
			$link = preg_replace( '#\/\/[^.]+\.imdb\.[^/]+\/#i', '//www.imdb.com/', $link );
		}

		return $link;
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		if ( is_string( $input->getOption( 'tmpDir' ) ) ) {
			$tmpDir = $input->getOption( 'tmpDir' );
		} else {
			$tmpDir = sys_get_temp_dir();
		}
		if ( !is_writable( $tmpDir ) ) {
			throw new RuntimeException( 'Temp dir: ' . $tmpDir . ' is not writable' );
		}

		$this->initServices( $tmpDir );
		unset( $tmpDir );

		$this->markLegacyProcessedIdsInCache();

		/** @var FormatterHelper $formatter */
		$formatter = $this->getHelper('formatter');
		$output->writeln( $formatter->formatBlock(
			array(
				'Wikidata Referencer',
				'This script is in development, If something goes wrong while you use it it is your fault!',
			),
			'info'
		) );

		// Get options
		$user = $input->getOption( 'user' );
		$userDetails = $this->appConfig->offsetGet( 'users.' . $user );
		if ( $userDetails === null ) {
			throw new RuntimeException( 'User not found in config' );
		}
		$sparqlQueryParts = $input->getOption( 'sparql' );
		$item = $input->getOption( 'item' );
		$force = false;

		// Get a list of ItemIds
		if( $item !== null ) {
			$output->writeln( $formatter->formatSection( 'Init', 'Using item passed in item parameter' ) );
			$itemIds = array( new ItemId( $item ) );
			// Force if explicitly passed an ItemId
			$force = true;
		} elseif( !empty( $sparqlQueryParts ) ) {
			$output->writeln( $formatter->formatSection( 'Init', 'Using items from SPARQL QUERY (running)' ) );
			$itemIds = $this->sparqlQueryRunner->getItemIdsForSimpleQueryParts( $sparqlQueryParts );
		} else {
			throw new RuntimeException( 'You must pass an instance id or an item' );
		}
		shuffle( $itemIds );
		$output->writeln( $formatter->formatSection( 'Init', 'Got ' . count( $itemIds ) . ' items to investigate' ) );

		// Log in to Wikidata
		$loggedIn =
			$this->wikibaseApi->login( new ApiUser( $userDetails['username'], $userDetails['password'] ) );
		if ( !$loggedIn ) {
			throw new RuntimeException( 'Failed to log in to wikibase wiki' );
		}

		$this->executeForItemIds(
			$output,
			$itemIds,
			$force
		);

		return 0;
	}

	/**
	 * @param OutputInterface $output
	 * @param ItemId[] $itemIds
	 * @param bool $force
	 */
	private function executeForItemIds( OutputInterface $output, array $itemIds, $force ) {
		$itemLookup = $this->wikibaseFactory->newItemLookup();
		/** @var FormatterHelper $formatter */
		$formatter = $this->getHelper('formatter');
		foreach ( $itemIds as $itemId ) {
			$itemIdString = $itemId->getSerialization();

			$output->writeln( '----------------------------------------------------' );

			if( !$force && $this->isIdProcessed( $itemId ) ) {
				$output->writeln( $formatter->formatSection( $itemIdString, 'Already processed' ) );
				continue;
			}

			try {
				$output->writeln( $formatter->formatSection( $itemIdString, 'Loading Item' ) );
				$item = $itemLookup->getItemForId( $itemId );
			}
			catch ( ItemLookupException $e ) {
				$output->writeln( $formatter->formatSection( $itemIdString, 'Failed to load item (exception)', 'error' ) );
				continue;
			}

			if ( $item === null ) {
				$output->writeln( $formatter->formatSection( $itemIdString, 'Failed to load item (null)', 'error' ) );
				continue;
			}

			// Get the item types..
			$types = array();
			foreach( $item->getStatements()->getByPropertyId( new PropertyId( 'P31' ) )->toArray() as $instanceStatement ) {
				$mainSnak = $instanceStatement->getMainSnak();
				if( $mainSnak instanceof PropertyValueSnak ) {
					/** @var EntityIdValue $instanceItemIdValue */
					$instanceItemIdValue = $mainSnak->getDataValue();
					$idSerialization = $instanceItemIdValue->getEntityId()->getSerialization();
					if( array_key_exists( $idSerialization, $this->instanceMap ) ) {
						$types[] = $this->instanceMap[$idSerialization];
					}
				}
			}
			if( empty( $types ) ) {
				$output->writeln( $formatter->formatSection( $itemIdString, 'Didn\t find any useful instance of statements', 'comment' ) );
				continue;
			}

			// Note: only load Wikipedias
			$siteLinkList = DataModelUtils::getSitelinksWiteSiteIdSuffix(
				$item->getSiteLinkList(),
				'wiki'
			);

			$output->writeln( $formatter->formatSection( $itemIdString, $siteLinkList->count() . ' Wikipedia pages to request' ) );
			$parseProgressBar = new ProgressBar( $output, $siteLinkList->count() );
			$parseProgressBar->display();
			/** @var PromiseInterface[] $parsePromises */
			$parsePromises = array();
			foreach ( $siteLinkList->getIterator() as $siteLink ) {
				$siteId = $siteLink->getSiteId();
				$pageName = $item->getSiteLinkList()->getBySiteId( $siteId )->getPageName();
				$sourceMwFactory = $this->wmFactoryFactory->getFactory( $siteId );
				$sourceParser = $sourceMwFactory->newParser();
				$pageIdentifier = new PageIdentifier( new Title( $pageName ) );
				$parsePromises[$siteId] = $sourceParser->parsePageAsync( $pageIdentifier );
				$parseProgressBar->advance();
			}
			$links = array();
			foreach ( $parsePromises as $siteId => $promise ) {
				try {
					$parseResult = $promise->wait();
					if ( array_key_exists( 'externallinks', $parseResult ) ) {
						foreach ( $parseResult['externallinks'] as $externalLink ) {
							// Ignore archive.org links
							if ( strstr( $externalLink, 'archive.org' ) === false ) {
								$links[] = $this->normalizeExternalLink( $externalLink );
							}
						}
					}
				}
				catch ( Exception $e ) {
					$parseProgressBar->clear();
					$output->writeln( $formatter->formatSection( $itemIdString, $e->getMessage(), 'error' ) );
					$parseProgressBar->display();
					// Ignore failed requests
				}
			}
			$parseProgressBar->finish();
			$output->writeln( '' );

			$links = array_unique( $links );
			shuffle( $links );

			/** @var Request[] $linkRequests */
			$linkRequests = array();
			foreach( $links as $link ) {
				$linkRequests[] = new Request(
					'GET',
					$link,
					array(
						'allow_redirects' => array( 'track_redirects' => true ),
						'connect_timeout' => 3.14,
						'timeout' => 10,
					)
				);
			}

			$output->writeln( $formatter->formatSection( $itemIdString, count( $linkRequests ) . ' External links to (download, action)' ) );
			if ( empty( $linkRequests ) ) {
				continue;
			}

			// Make a bunch of requests and act on the responses
			$referencesAddedToItem = 0;
			$externalLinkProgressBar = new ProgressBar( $output, count( $linkRequests ) * 2 );
			$externalLinkProgressBar->display();

			$pool = new RequestPool(
				$this->externalLinkClient,
				$linkRequests,
				array(
					'fulfilled' => function ( $response )
					use ( $externalLinkProgressBar, $item, $types, $referencesAddedToItem, $output ) {
						$externalLinkProgressBar->advance(); // 1st advance point

						if ( $response instanceof ResponseInterface ) {
							$link = $response->getHeaderLine( 'X-GUZZLE-EFFECTIVE-URL' );
							$html = $response->getBody();
							$referencesAddedFromLink = 0;

							foreach( $this->microDataExtractor->extract( $html ) as $microData ) {
								foreach( $types as $type ) {
									if( $microData->hasType( $type ) && array_key_exists( $type, $this->referencerMap ) )
										foreach( $this->referencerMap[$type] as $referencer ) {
											/** @var Referencer $referencer */
											$addedReferences = $referencer->addReferences( $microData, $item, $link );
											$referencesAddedToItem += $addedReferences;
											$referencesAddedFromLink += $addedReferences;
										}
								}
							}
							if ( $referencesAddedFromLink > 0 ) {
								$externalLinkProgressBar->clear();
								$output->write( "\x0D" );
								$output->writeln( $referencesAddedFromLink . ' reference(s) added from ' . urldecode( $link ) );
								$externalLinkProgressBar->display();
							}

						}
						$externalLinkProgressBar->advance(); // 2nd advance point
					},

					'rejected' => function () use ( $externalLinkProgressBar ) {
						// TODO add this to some kind of verbose log?
						$externalLinkProgressBar->advance(); // 1st advance point
					},
				)
			);

			$pool->promise()->wait();
			$externalLinkProgressBar->finish();
			$output->writeln( '' );
			$output->writeln( $formatter->formatSection( $itemIdString, $referencesAddedToItem . ' References added' ) );

			$this->markIdAsProcessed( $itemId );
		}
	}

	private function markLegacyProcessedIdsInCache() {
		$path = $this->oldProcessedListPath;
		if( file_exists( $path ) ) {
			$list = explode( PHP_EOL, file_get_contents( $path ) );
			foreach( $list as $itemIdString ) {
				$this->markIdAsProcessed( new ItemId( $itemIdString ) );
			}
		}
	}

	private function markIdAsProcessed( ItemId $itemId ) {
		$this->cache->getItem( 'processed/' . $itemId )->set( 1 )->save();
	}

	private function isIdProcessed( ItemId $itemId ) {
		return $this->cache->hasItem( 'processed/' . $itemId );
	}

}
