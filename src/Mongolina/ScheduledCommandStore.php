<?php


namespace Mongolina;


use Mongolina\ScheduledCommand\ScheduledCommandStoreTrait;
use Mongolina\ScheduledCommand\ScheduledCommandWithMetadata;
use MongoDB\BSON\UTCDateTime;

class ScheduledCommandStore implements \Dudulina\Scheduling\ScheduledCommandStore
{
    use ScheduledCommandStoreTrait;

    public function loadAndProcessScheduledCommands(callable $eventProcessor/** function(ScheduledCommand $scheduledCommand, array $metadata = null) */)
    {
        while (($commandWithMetadata = $this->loadOneCommand())) {
            \call_user_func($eventProcessor, $commandWithMetadata->getScheduledCommand(), $commandWithMetadata->getCommandMetadata());
        }
    }

    private function loadOneCommand():?ScheduledCommandWithMetadata
    {
        $document = $this->collection->findOneAndDelete([
            'scheduleAt' => [
                '$lte' => new UTCDateTime(time() * 1000),
            ],
        ], [
            'sort' => ['scheduleAt' => 1],
        ]);

        if (!$document) {
            return null;
        }

        return $this->hydrateCommand($document);
    }

    private function hydrateCommand($document): ScheduledCommandWithMetadata
    {
        return new ScheduledCommandWithMetadata(
            \unserialize($document['command']),
            $document['commandMetadata'] ? \unserialize($document['commandMetadata']) : null);
    }

    public function cancelCommand($commandId)
    {
        $this->collection->deleteOne([
            '_id' => $this->messageIdToMongoId($commandId),
        ]);
    }
}