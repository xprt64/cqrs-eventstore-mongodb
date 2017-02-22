<?php
/******************************************************************************
 * Copyright (c) 2016 Constantin Galbenu <gica.galbenu@gmail.com>             *
 ******************************************************************************/

namespace Gica\Cqrs\EventStore\Mongo;


use Gica\Cqrs\Event\EventWithMetaData;
use Gica\Cqrs\EventStore\ByClassNamesEventStream;
use Gica\Iterator\IteratorTransformer\IteratorExpander;
use Gica\Iterator\IteratorTransformer\IteratorMapper;
use MongoDB\Collection;
use MongoDB\Driver\Cursor;

class MongoAllEventByClassesStream implements ByClassNamesEventStream
{
    use EventStreamIteratorTrait;

    /**
     * @var Collection
     */
    private $collection;
    /**
     * @var EventSerializer
     */
    private $eventSerializer;
    /**
     * @var array
     */
    private $eventClassNames;

    /** @var int|null */
    private $limit = null;

    /** @var int|null */
    private $afterSequence;

    public function __construct(
        Collection $collection,
        array $eventClassNames,
        EventSerializer $eventSerializer
    )
    {
        $this->collection = $collection;
        $this->eventSerializer = $eventSerializer;
        $this->eventClassNames = $eventClassNames;
    }

    /**
     * @inheritdoc
     */
    public function limitCommits(int $limit)
    {
        $this->limit = $limit;
    }

    /**
     * @inheritdoc
     */
    public function afterSequence(int $afterSequence)
    {
        $this->afterSequence = $afterSequence;
    }

    public function countCommits(): int
    {
        return $this->collection->count($this->getFilter());
    }

    /**
     * @inheritdoc
     */
    public function getIterator()
    {
        $cursor = $this->getCursor();

        return $this->getIteratorThatExtractsInterestingEventsFromDocument($cursor);
    }

    private function getCursor(): Cursor
    {
        $options = [
            'sort' => [
                'sequence' => 1,
            ],
        ];

        if ($this->limit > 0) {
            $options['limit'] = $this->limit;
        }

        $cursor = $this->collection->find(
            $this->getFilter(),
            $options
        );

        return $cursor;
    }

    private function getIteratorThatExtractsInterestingEventsFromDocument($cursor): \Traversable
    {
        $expanderCallback = function ($document) {
            $metaData = $this->extractMetaDataFromDocument($document);

            foreach ($document['events'] as $eventSubDocument) {
                if (!$this->isInterestingEvent($eventSubDocument[MongoEventStore::EVENT_CLASS])) {
                    continue;
                }

                $event = $this->eventSerializer->deserializeEvent($eventSubDocument[MongoEventStore::EVENT_CLASS], $eventSubDocument['payload']);

                yield new EventWithMetaData($event, $metaData);
            }
        };

        $generator = new IteratorExpander($expanderCallback);

        return $generator($cursor);
    }

    private function isInterestingEvent($eventClass)
    {
        return empty($this->eventClassNames) || in_array($eventClass, $this->eventClassNames);
    }

    private function getFilter(): array
    {
        $filter = [];

        if ($this->eventClassNames) {
            $filter[MongoEventStore::EVENTS_EVENT_CLASS] = [
                '$in' => $this->eventClassNames,
            ];
        }

        if ($this->afterSequence !== null) {
            $filter['sequence'] = [
                '$gt' => $this->afterSequence,
            ];
        }

        return $filter;
    }

    /**
     * @return array|\ArrayIterator
     */
    public function fetchCommits()
    {
        $cursor = $this->getCursor();

        return $this->getIteratorForCommits($cursor);
    }

    private function getIteratorForCommits($cursor): \Traversable
    {
        $filterCallback = function ($document) {
            $metaData = $this->extractMetaDataFromDocument($document);

            $result = [];

            foreach ($document['events'] as $eventSubDocument) {
                if (!$this->isInterestingEvent($eventSubDocument[MongoEventStore::EVENT_CLASS])) {
                    continue;
                }

                $event = $this->eventSerializer->deserializeEvent($eventSubDocument[MongoEventStore::EVENT_CLASS], $eventSubDocument['payload']);

                $result[] = new EventWithMetaData($event, $metaData);
            }

            return $result;
        };

        $generator = new IteratorMapper($filterCallback);

        return $generator($cursor);
    }

}