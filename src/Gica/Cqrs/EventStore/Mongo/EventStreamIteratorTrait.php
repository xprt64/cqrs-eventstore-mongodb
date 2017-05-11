<?php
/******************************************************************************
 * Copyright (c) 2016 Constantin Galbenu <gica.galbenu@gmail.com>             *
 ******************************************************************************/

namespace Gica\Cqrs\EventStore\Mongo;

use Gica\Cqrs\Event\EventWithMetaData;
use Gica\Cqrs\Event\MetaData;
use Gica\Iterator\IteratorTransformer\IteratorExpander;
use MongoDB\BSON\UTCDateTime;

/**
 * @property \Gica\Cqrs\EventStore\Mongo\EventSerializer $eventSerializer
 */
trait EventStreamIteratorTrait
{
    private function getIteratorThatExtractsEventsFromDocument($cursor): \Traversable
    {
        $expanderCallback = function ($document) {
            $metaData = $this->extractMetaDataFromDocument($document);

            foreach ($document['events'] as $index => $eventSubDocument) {
                $event = $this->eventSerializer->deserializeEvent($eventSubDocument['eventClass'], $eventSubDocument['payload']);

                if ($eventSubDocument['id']) {
                    $metaData = $metaData->withEventId($eventSubDocument['id']);
                }

                if ($document['sequence']) {
                    $metaData = $metaData->withSequenceAndIndex($document['sequence'], $index);
                }

                yield new EventWithMetaData($event, $metaData->withEventId($eventSubDocument['id']));
            }

        };

        $generator = new IteratorExpander($expanderCallback);

        return $generator($cursor);
    }

    private function extractMetaDataFromDocument($document)
    {
        /** @var UTCDateTime $createdAt */
        $createdAt = $document['createdAt'];
        $dateCreated = \DateTimeImmutable::createFromMutable($createdAt->toDateTime());

        return new MetaData(
            (string)$document['aggregateId'],
            $document['aggregateClass'],
            $dateCreated,
            $document['authenticatedUserId'] ? $document['authenticatedUserId'] : null
        );
    }

    private function extractSequenceFromDocument($document)
    {
        return $document['sequence'];
    }

    private function extractVersionFromDocument($document)
    {
        return $document['version'];
    }
}