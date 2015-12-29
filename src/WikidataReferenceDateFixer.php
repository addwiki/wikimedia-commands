<?php

namespace Addwiki\Commands\Wikimedia;

use ArrayAccess;
use DataValues\Deserializers\DataValueDeserializer;
use DataValues\Serializers\DataValueSerializer;
use DataValues\TimeValue;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\DataModel\EditInfo;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wikibase\Api\WikibaseFactory;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;

/**
 * @author Addshore
 */
class WikidataReferenceDateFixer extends Command {

	private $appConfig;

	/**
	 * @var WikibaseFactory
	 */
	private $wikibaseFactory;

	/**
	 * @var MediawikiApi
	 */
	private $wikibaseApi;

	public function __construct( ArrayAccess $appConfig ) {
		$this->appConfig = $appConfig;

		$this->wikibaseApi = new MediawikiApi( "https://www.wikidata.org/w/api.php" );
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
		parent::__construct( null );
	}

	protected function configure() {
		$defaultUser = $this->appConfig->offsetGet( 'defaults.user' );

		$this
			->setName( 'wm:wd:ref-retrieved-cal-fix' )
			->setDescription( 'Switches reference calendar models to Gregorian' )
			->addOption(
				'user',
				null,
				( $defaultUser === null ? InputOption::VALUE_REQUIRED :
					InputOption::VALUE_OPTIONAL ),
				'The configured user to use',
				$defaultUser
			)
			->addOption(
				'item',
				null,
				InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
				'Item to target'
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		// Get options
		$user = $input->getOption( 'user' );
		$userDetails = $this->appConfig->offsetGet( 'users.' . $user );
		if ( $userDetails === null ) {
			throw new RuntimeException( 'User not found in config' );
		}
		$items = $input->getOption( 'item' );

		if( empty( $items ) ) {
			throw new RuntimeException( 'You must pass an item' );
		}

		/** @var ItemId[] $itemIds */
		$itemIds = array();
		foreach( array_unique( $items ) as $itemIdString ) {
			$itemIds[] = new ItemId( $itemIdString );
		}

		// Log in to Wikidata
		$loggedIn =
			$this->wikibaseApi->login( new ApiUser( $userDetails['username'], $userDetails['password'] ) );
		if ( !$loggedIn ) {
			$output->writeln( 'Failed to log in to wikidata wiki' );
			return -1;
		}

		$itemLookup = $this->wikibaseFactory->newItemLookup();

		foreach ( $itemIds as $itemId ) {
			$output->write( $itemId->getSerialization() . ' ' );
			$item = $itemLookup->getItemForId( $itemId );

			foreach ( $item->getStatements()->getIterator() as $statement ) {
				foreach ( $statement->getReferences() as $reference ) {
					/** @var Reference $reference */
					foreach ( $reference->getSnaks()->getIterator() as $snak ) {
						if ( $snak instanceof PropertyValueSnak ) {
							if ( $snak->getPropertyId()->getSerialization() == 'P813' ) {
								/** @var TimeValue $dataValue */
								$dataValue = $snak->getDataValue();
								// We can assume ALL retreival dates should be Gregorian!
								if ( $dataValue->getCalendarModel() === TimeValue::CALENDAR_JULIAN ) {
									$oldRefHash = $reference->getHash();
									$statementGuid = $statement->getGuid();

									$snakList = $reference->getSnaks();
									$snakList = new SnakList( $snakList->getArrayCopy() );
									$snakList->removeSnak( $snak );
									$snakList->addSnak(
										new PropertyValueSnak(
											new PropertyId( 'P813' ),
											new TimeValue(
												$dataValue->getTime(),
												$dataValue->getTimezone(),
												$dataValue->getBefore(),
												$dataValue->getAfter(),
												$dataValue->getPrecision(),
												TimeValue::CALENDAR_GREGORIAN
											)
										)
									);

									$this->wikibaseFactory->newReferenceSetter()->set(
										new Reference( $snakList ),
										$statementGuid,
										$oldRefHash,
										new EditInfo(
											'Switch Julian retrieval date calendar model to Gregorian'
										)
									);
									$output->write( '.' );
								}
							}
						}
					}
				}
			}
			$output->writeln('');
		}

		return 0;
	}

}
