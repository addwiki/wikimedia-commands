<?php

namespace Addwiki\Commands\Wikimedia\WikidataReferencer;

use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Services\Lookup\InMemoryEntityLookup;

/**
 * @author Addshore
 */
interface Referencer {

	/**
	 * @param MicroData $microData
	 * @param Item $item
	 * @param string $sourceUrl
	 *
	 * @return int the number of references added
	 */
	public function addReferences( MicroData $microData, $item, $sourceUrl );

}