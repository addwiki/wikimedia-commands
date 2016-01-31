<?php

namespace Addwiki\Commands\Wikimedia;

use ArrayAccess;
use DataValues\Deserializers\DataValueDeserializer;
use DataValues\Serializers\DataValueSerializer;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiFactory;
use Mediawiki\DataModel\Content;
use Mediawiki\DataModel\Page;
use Mediawiki\DataModel\Revision;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wikibase\Api\WikibaseFactory;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * @author Addshore
 */
class ExtensionToWikidata extends Command {

	private $appConfig;

	/**
	 * @var MediawikiApi
	 */
	private $wikidataApi;

	/**
	 * @var MediawikiApi
	 */
	private $mediawikiApi;

	/**
	 * @var MediawikiFactory
	 */
	private $mediawikiFactory;

	/**
	 * @var WikibaseFactory
	 */
	private $wikidataFactory;

	public function __construct( ArrayAccess $appConfig ) {
		$this->appConfig = $appConfig;
		parent::__construct( null );
	}

	protected function configure() {
		$defaultUser = $this->appConfig->offsetGet( 'defaults.user' );

		$this
			->setName( 'wm:exttowd' )
			->setDescription( 'Edits the page' )
			->addOption(
				'user',
				null,
				( $defaultUser === null ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL),
				'The configured user to use',
				$defaultUser
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$user = $input->getOption( 'user' );

		$userDetails = $this->appConfig->get( 'users.' . $user );

		if ( $userDetails === null ) {
			throw new RuntimeException( 'User not found in config' );
		}

		$this->setServices();

		$loggedIn = $this->wikidataApi->login(
				new ApiUser(
					$userDetails['username'],
					$userDetails['password']
				)
		);
		if ( !$loggedIn ) {
			$output->writeln( 'Failed to log in to target wiki' );

			return -1;
		}

		$allExtensionPages = $this->mediawikiFactory
			->newPageListGetter()
			->getPageListFromCategoryName(
				'Category:All_extensions',
				array(
					'cmtype' => 'page',
					'cmnamespace' => 102,
				)
			);

		foreach ( $allExtensionPages->toArray() as $page ) {
			$this->processPage( $page, $output );
		}

		return 0;
	}

	private function setServices() {
		$this->mediawikiApi = new MediawikiApi( "https://www.mediawiki.org/w/api.php" );
		$this->wikidataApi = new MediawikiApi( "https://www.wikidata.org/w/api.php" );

		$this->mediawikiFactory = new MediawikiFactory( $this->mediawikiApi );

		$this->wikidataFactory = new WikibaseFactory(
			$this->wikidataApi,
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
	}

	private function processPage( Page $page, OutputInterface $output ) {
		$sourceParser = $this->mediawikiFactory->newParser();
		$parseResult = $sourceParser->parsePage( $page->getPageIdentifier() );

		//Get the wikibase item if it exists
		$itemIdString = null;
		if( array_key_exists( 'properties', $parseResult ) ) {
			foreach( $parseResult['properties'] as $pageProp ) {
				if( $pageProp['name'] == 'wikibase_item' ) {
					$itemIdString = $pageProp['*'];
				}
			}
		}

		// Create an item if there is no item yet!
		if( $itemIdString === null ) {
			$sourceTitle = $page->getPageIdentifier()->getTitle()->getText();
			$output->writeln( "Creating a new Item for: " . $sourceTitle );
			$item = new Item();
			$item->setLabel( 'en', $sourceTitle );
			//TODO this siteid should come from somewhere?
			$item->getSiteLinkList()->setNewSiteLink( 'mediawikiwiki', $sourceTitle );
			$targetRevSaver = $this->wikidataFactory->newRevisionSaver();
			$item = $targetRevSaver->save( new Revision( new Content( $item ) ) );
		} else {
			$item = $this->wikidataFactory->newItemLookup()->getItemForId( new ItemId( $itemIdString ) );
		}

		// Add instance of if not already there
		$hasInstanceOfExtension = false;
		foreach( $item->getStatements()->getByPropertyId( new PropertyId( 'P31' ) )->getMainSnaks() as $mainSnak ) {
			if( $mainSnak instanceof PropertyValueSnak ) {
				/** @var EntityIdValue $dataValue */
				$dataValue = $mainSnak->getDataValue();
				if( $dataValue->getEntityId()->equals( new ItemId( 'Q6805426' ) ) ) {
					$hasInstanceOfExtension = true;
					break;
				}
			}
		}
		if( !$hasInstanceOfExtension ) {
			$output->writeln( "Creating instance of Statement" );
			$this->wikidataFactory->newStatementCreator()->create(
				new PropertyValueSnak(
					new PropertyId( 'P31' ),
					new EntityIdValue( new ItemId( 'Q6805426' ) )
				),
				$item->getId()
			);
		}

		// Try to add a licence
		$catLicenseMap = array(
			'Public_domain_licensed_extensions' => 'Q19652',
		);
		$extensionLicenseItemIdString = null;
		if( array_key_exists( 'categories', $parseResult ) ) {
			foreach( $parseResult['categories'] as $categoryInfo ) {
				if( array_key_exists( $categoryInfo['*'], $catLicenseMap ) ) {
					$extensionLicenseItemIdString = $catLicenseMap[$categoryInfo['*']];
				}
			}
		}
		if( $extensionLicenseItemIdString !== null ) {
			$output->writeln( "Creating Licence Statement" );
			$statementCreator = $this->wikidataFactory->newStatementCreator();
			//TODO make sure it isn't already there????
			$statementCreator->create(
				new PropertyValueSnak(
					new PropertyId( 'P275' ),
					new EntityIdValue( new ItemId( $extensionLicenseItemIdString ) )
				),
				$item->getId()
			);
		}

	}

}
