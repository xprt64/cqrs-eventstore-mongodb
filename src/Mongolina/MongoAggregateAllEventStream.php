<?php
/******************************************************************************
 * Copyright (c) 2016 Constantin Galbenu <gica.galbenu@gmail.com>             *
 ******************************************************************************/

namespace Mongolina;


use Dudulina\Aggregate\AggregateDescriptor;
use Dudulina\EventStore\AggregateEventStream;
use MongoDB\BSON\ObjectID;
use MongoDB\Collection;
use MongoDB\Driver\Cursor;

class MongoAggregateAllEventStream implements AggregateEventStream
{
    /** @var Collection */
    private $collection;

    /** @var int */
    private $version;

    /** @var EventStreamIterator */
    private $eventStreamIterator;

    /** @var AggregateDescriptor */
    private $aggregateDescriptor;

    public function __construct(
        Collection $collection,
        AggregateDescriptor $aggregateDescriptor,
        EventStreamIterator $eventStreamIterator
    )
    {
        $this->collection = $collection;
        $this->version = $this->fetchLatestVersion($aggregateDescriptor);
        $this->eventStreamIterator = $eventStreamIterator;
        $this->aggregateDescriptor = $aggregateDescriptor;
    }

    public function getIterator()
    {
        return $this->eventStreamIterator->getIteratorThatExtractsEventsFromDocument(
            $this->getCursorLessThanOrEqualToVersion($this->aggregateDescriptor));
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    private function fetchLatestVersion(AggregateDescriptor $aggregateDescriptor): int
    {
        return (new LastAggregateVersionFetcher())->fetchLatestVersion($this->collection, $aggregateDescriptor);
    }

    private function getCursorLessThanOrEqualToVersion(AggregateDescriptor $aggregateDescriptor): Cursor
    {
        return $this->getCursorLessThanOrEqualToCurrentVersion(StreamName::factoryStreamNameFromDescriptor($aggregateDescriptor));
    }

    public function getCursorLessThanOrEqualToCurrentVersion(string $streamName): Cursor
    {
        return $this->collection->find(
            [
                'streamName' => new ObjectID($streamName),
                'version'    => [
                    '$lte' => $this->version,
                ],
            ],
            [
                'sort' => [
                    'version' => 1,
                ],
            ]
        );
    }

    public function count()
    {
        $pipeline = [];

        $pipeline[] = [
            '$match' => [
                'streamName' => StreamName::factoryStreamNameFromDescriptor($this->aggregateDescriptor),
                'version'    => [
                    '$lte' => $this->version,
                ],
            ],
        ];

        $pipeline[] = [
            '$count' => 'total',
        ];

        return $this->collection->aggregate(
            $pipeline
        )['total'];
    }
}