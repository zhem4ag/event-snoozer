<?php

namespace EventSnoozer\Tests;

use EventSnoozer\EventSnoozer;
use EventSnoozer\EventStorage\EventStorageInterface;
use EventSnoozer\StoredEvent\StoredEvent;
use EventSnoozer\Tests\Mocks\TestEvent;
use EventSnoozer\Tests\Mocks\TestEventListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use EventSnoozer\Tests\Constraints\IsSameStoredEvent;
use EventSnoozer\EventStorage\NullEventStorage;

class EventSnoozerTest extends TestCase
{
    public function testSnoozeEvent()
    {
        $firstEvent = new TestEvent();
        $firstStoredEvent = new StoredEvent();
        $firstStoredEvent->setEventClass(TestEvent::class)
            ->setEventName('test.event')
            ->setRuntime(new \DateTime('+1 min'))
            ->setPriority(0)
            ->setAdditionalData([]);

        $secondEvent = new TestEvent();
        $secondEvent->setPriority(123)
            ->setAdditionalData(['key' => 'value']);
        $secondStoredEvent = new StoredEvent();
        $secondStoredEvent->setEventClass(TestEvent::class)
            ->setEventName('test.event')
            ->setRuntime(new \DateTime('+2 hour'))
            ->setPriority(123)
            ->setAdditionalData(['key' => 'value']);

        $eventStorageMock = $this->getMockBuilder(NullEventStorage::class)
            ->setMethods(['saveEvent'])
            ->getMock();
        $eventStorageMock->expects(self::at(0))
            ->method('saveEvent')
            ->with(new IsSameStoredEvent($firstStoredEvent));
        $eventStorageMock->expects(self::at(1))
            ->method('saveEvent')
            ->with(new IsSameStoredEvent($secondStoredEvent));
        /** @var EventStorageInterface $eventStorageMock */

        $eventSnoozer = new EventSnoozer(new EventDispatcher(), $eventStorageMock);
        $eventSnoozer->snoozeEvent(TestEvent::NAME, $firstEvent);
        $eventSnoozer->snoozeEvent(TestEvent::NAME, $secondEvent, '+2 hour');
    }

    public function testDispatchSnoozedEvent()
    {
        $eventListener = new TestEventListener();
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(TestEvent::NAME, [$eventListener, 'onTestEvent']);

        $storedEvent = new StoredEvent();
        $storedEvent->setEventClass(TestEvent::class)
            ->setEventName('test.event')
            ->setRuntime(new \DateTime('-1 min'))
            ->setPriority(2)
            ->setAdditionalData(['key' => 'value'])
            ->setId(234);

        $eventStorageMock = $this->getMockBuilder(NullEventStorage::class)
            ->setMethods(['fetchEvent', 'removeEvent'])
            ->getMock();
        $eventStorageMock->expects(self::at(0))
            ->method('fetchEvent')
            ->willReturn($storedEvent);
        $eventStorageMock->expects(self::at(1))
            ->method('removeEvent')
            ->with($storedEvent);
        $eventStorageMock->expects(self::at(2))
            ->method('fetchEvent')
            ->willReturn(null);
        /** @var EventStorageInterface $eventStorageMock */

        $eventSnoozer = new EventSnoozer($eventDispatcher, $eventStorageMock);
        $eventSnoozer->dispatchSnoozedEvent();
        $eventSnoozer->dispatchSnoozedEvent();

        $dispatchedEvents = $eventListener->firedEvents;
        self::assertCount(1, $dispatchedEvents);
        $dispatchedEvent = array_shift($dispatchedEvents);
        self::assertInstanceOf(TestEvent::class, $dispatchedEvent);
        self::assertSame(2, $dispatchedEvent->getPriority());
        self::assertSame(['key' => 'value'], $dispatchedEvent->getAdditionalData());
    }

    public function testDispatchMultipleSnoozedEvent()
    {
        $eventListener = new TestEventListener();
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(TestEvent::NAME, [$eventListener, 'onTestEvent']);

        $storedEvent = new StoredEvent();
        $storedEvent->setEventClass(TestEvent::class)
            ->setEventName('test.event')
            ->setRuntime(new \DateTime('-1 hour'))
            ->setPriority(2)
            ->setAdditionalData(['key' => 'value'])
            ->setId(234);
        $storedEvent2 = new StoredEvent();
        $storedEvent2->setEventClass(TestEvent::class)
            ->setEventName('test.event')
            ->setRuntime(new \DateTime('-1 min'))
            ->setPriority(20)
            ->setAdditionalData(['key2' => 'value2'])
            ->setId(345);

        $eventStorageMock = $this->getMockBuilder(NullEventStorage::class)
            ->setMethods(['fetchMultipleEvents', 'removeEvent'])
            ->getMock();
        $eventStorageMock->expects(self::at(0))
            ->method('fetchMultipleEvents')
            ->with(1)
            ->willReturn([$storedEvent]);
        $eventStorageMock->expects(self::at(1))
            ->method('removeEvent')
            ->with($storedEvent);
        $eventStorageMock->expects(self::at(2))
            ->method('fetchMultipleEvents')
            ->with(2)
            ->willReturn([$storedEvent2, $storedEvent]);
        $eventStorageMock->expects(self::at(3))
            ->method('removeEvent')
            ->with($storedEvent2);
        $eventStorageMock->expects(self::at(4))
            ->method('removeEvent')
            ->with($storedEvent);
        $eventStorageMock->expects(self::at(5))
            ->method('fetchMultipleEvents')
            ->with(2)
            ->willReturn([$storedEvent]);
        $eventStorageMock->expects(self::at(6))
            ->method('removeEvent')
            ->with($storedEvent);
        /** @var EventStorageInterface $eventStorageMock */

        $eventSnoozer = new EventSnoozer($eventDispatcher, $eventStorageMock);
        $eventSnoozer->dispatchMultipleSnoozedEvents();
        $dispatchedEvents = $eventListener->firedEvents;
        self::assertCount(1, $dispatchedEvents);
        $dispatchedEvent = array_shift($dispatchedEvents);
        self::assertInstanceOf(TestEvent::class, $dispatchedEvent);
        self::assertSame(2, $dispatchedEvent->getPriority());
        self::assertSame(['key' => 'value'], $dispatchedEvent->getAdditionalData());
        $eventListener->firedEvents = [];

        $eventSnoozer->dispatchMultipleSnoozedEvents(2);
        $dispatchedEvents = $eventListener->firedEvents;
        self::assertCount(2, $dispatchedEvents);
        $firstDispatchedEvent = array_shift($dispatchedEvents);
        self::assertInstanceOf(TestEvent::class, $firstDispatchedEvent);
        self::assertSame(20, $firstDispatchedEvent->getPriority());
        self::assertSame(['key2' => 'value2'], $firstDispatchedEvent->getAdditionalData());
        $lastDispatchedEvent = array_pop($dispatchedEvents);
        self::assertInstanceOf(TestEvent::class, $lastDispatchedEvent);
        self::assertSame(2, $lastDispatchedEvent->getPriority());
        self::assertSame(['key' => 'value'], $lastDispatchedEvent->getAdditionalData());
        $eventListener->firedEvents = [];

        $eventSnoozer->dispatchMultipleSnoozedEvents(2);
        $dispatchedEvents = $eventListener->firedEvents;
        self::assertCount(1, $dispatchedEvents);
        $dispatchedEvent = array_shift($dispatchedEvents);
        self::assertInstanceOf(TestEvent::class, $dispatchedEvent);
        self::assertSame(2, $dispatchedEvent->getPriority());
        self::assertSame(['key' => 'value'], $dispatchedEvent->getAdditionalData());
        $eventListener->firedEvents = [];
    }
}
